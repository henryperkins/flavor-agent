<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Embeddings\BaseHttpClient;
use FlavorAgent\Embeddings\ConfigurationValidator;
use FlavorAgent\Embeddings\EmbeddingClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class EmbeddingBackendValidationTest extends TestCase {



	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	protected function tearDown(): void {
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
						'message' => 'Workers AI model not found',
					],
				]
			),
		];

		$result = EmbeddingClient::validate_configuration(
			'account-123',
			'workers-token',
			'missing-model'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'Workers AI model not found', $result->get_error_message() );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
			WordPressTestState::$last_remote_post['url']
		);
	}

	public function test_embedding_validation_returns_actionable_authentication_message_for_unauthorized_response(): void {
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 401,
			],
			'body'     => wp_json_encode(
				[
					'success' => false,
					'errors'  => [
						[
							'code'    => 10000,
							'message' => 'Authentication error',
						],
					],
				]
			),
		];

		$result = EmbeddingClient::validate_configuration(
			'account-123',
			'workers-token',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Cloudflare Workers AI authentication failed. Check the account ID, API token, and Workers AI Read/Edit permissions in Settings > Flavor Agent.',
			$result->get_error_message()
		);
	}

	public function test_embedding_batch_returns_actionable_authentication_message_for_unauthorized_response(): void {
		WordPressTestState::$options                 = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];
		WordPressTestState::$remote_post_responses[] = [
			'response' => [
				'code' => 401,
			],
			'body'     => wp_json_encode(
				[
					'success' => false,
					'errors'  => [
						[
							'code'    => 10000,
							'message' => 'Authentication error',
						],
					],
				]
			),
		];

		$result = EmbeddingClient::embed_batch( [ 'pattern query' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embedding_error', $result->get_error_code() );
		$this->assertSame(
			'Cloudflare Workers AI authentication failed. Check the account ID, API token, and Workers AI Read/Edit permissions in Settings > Flavor Agent.',
			$result->get_error_message()
		);
	}

	public function test_embedding_validation_returns_workers_ai_missing_credentials_message(): void {
		$result = EmbeddingClient::validate_configuration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
		$this->assertSame(
			'Cloudflare Workers AI embedding credentials are not configured. Go to Settings > Flavor Agent.',
			$result->get_error_message()
		);
	}

	public function test_shared_configuration_validator_missing_credentials_message_mentions_workers_ai(): void {
		$result = ConfigurationValidator::validate_with_response(
			'',
			[],
			'',
			[ 'input' => [ 'validation' ] ],
			'embedding_validation_error',
			'Cloudflare Workers AI embeddings',
			'embeddings'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
		$this->assertSame(
			'Cloudflare Workers AI credentials are not configured. Go to Settings > Flavor Agent.',
			$result->get_error_message()
		);
	}

	public function test_shared_configuration_validator_rejects_empty_bearer_authorization_header(): void {
		$result = ConfigurationValidator::validate_with_response(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
			[
				'Authorization' => 'Bearer ',
				'Content-Type'  => 'application/json',
			],
			'@cf/qwen/qwen3-embedding-0.6b',
			[ 'input' => [ 'validation' ] ],
			'embedding_validation_error',
			'Cloudflare Workers AI embeddings',
			'embeddings'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_parse_retry_after_header_supports_http_dates(): void {
		$now    = time();
		$header = gmdate( 'D, d M Y H:i:s \G\M\T', $now + 7 );
		$parsed = BaseHttpClient::parse_retry_after_header( $header, $now );

		$this->assertSame( 7, $parsed );
	}

	public function test_parse_retry_after_header_caps_foreground_waits_unless_the_caller_allows_longer(): void {
		$this->assertSame( 60, BaseHttpClient::parse_retry_after_header( '240' ) );
		$this->assertSame( 240, BaseHttpClient::parse_retry_after_header( '240', null, 300 ) );
		$this->assertSame( 300, BaseHttpClient::parse_retry_after_header( '900', null, 300 ) );
	}

	public function test_rank_ignores_saved_connector_provider_and_uses_wordpress_ai_client(): void {
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
		WordPressTestState::$ai_client_supported        = true;

		WordPressTestState::$ai_client_generate_text_result = 'connector ranked output';

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt', 'high' );

		$this->assertSame( 'connector ranked output', $result );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( 'connector system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_uses_saved_reasoning_effort_without_saved_provider_pinning(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'  => 'anthropic',
			Config::OPTION_REASONING_EFFORT => 'xhigh',
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
		WordPressTestState::$ai_client_supported        = true;

		WordPressTestState::$ai_client_generate_text_result = 'connector ranked output';

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt' );

		$this->assertSame( 'connector ranked output', $result );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( 'xhigh', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_ignores_saved_openai_native_provider(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider' => 'openai_native',
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
		WordPressTestState::$ai_client_supported        = true;

		WordPressTestState::$ai_client_generate_text_result = 'OpenAI connector ranked output';

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt', 'high' );

		$this->assertSame( 'OpenAI connector ranked output', $result );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( 'connector system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_uses_generic_wordpress_ai_client_for_direct_provider(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider' => 'openai_native',
		];

		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'WordPress AI client preferred output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertSame( 'WordPress AI client preferred output', $result );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( 'fallback system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_uses_unpinned_wordpress_ai_client_when_workers_ai_embeddings_are_selected(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'Workers AI embedding path ranked output';

		$result = ResponsesClient::rank( 'connector system prompt', 'connector user prompt', 'high' );

		$this->assertSame( 'Workers AI embedding path ranked output', $result );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( 'connector system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_embed_batch_returns_a_retryable_rate_limit_error_without_sleeping(): void {
		WordPressTestState::$options               = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
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
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
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


	public function test_embed_batch_rejects_mixed_dimensions(): void {
		WordPressTestState::$options              = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
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

	public function test_rank_uses_generic_wordpress_ai_client_when_no_chat_connector_is_selected(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'Generic WordPress AI Client output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertSame( 'Generic WordPress AI Client output', $result );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( 'fallback system prompt', WordPressTestState::$last_ai_client_prompt['system'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_uses_saved_reasoning_effort_without_openai_provider_pinning(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider'  => 'openai_native',
			Config::OPTION_REASONING_EFFORT => 'high',
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
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_rank_does_not_use_openai_custom_options_for_unpinned_generic_runtime(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider'  => 'openai_native',
			Config::OPTION_REASONING_EFFORT => 'high',
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
		WordPressTestState::$ai_client_feature_support      = [
			'reasoning' => false,
		];
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = 'OpenAI connector output';

		$result = ResponsesClient::rank( 'fallback system prompt', 'fallback user prompt' );

		$this->assertSame( 'OpenAI connector output', $result );
		$this->assertArrayNotHasKey( 'provider', WordPressTestState::$last_ai_client_prompt );
		$this->assertArrayNotHasKey( 'reasoning', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame( [], WordPressTestState::$last_ai_client_prompt['customOptions'] ?? [] );
		$this->assertSame( 16384, WordPressTestState::$last_ai_client_prompt['model_config']['maxTokens'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_embedding_validation_wraps_transport_timeout_with_backend_context(): void {
		WordPressTestState::$remote_post_response = new \WP_Error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received'
		);

		$result = EmbeddingClient::validate_configuration(
			'account-123',
			'workers-token',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
		$this->assertStringContainsString(
			'Cloudflare Workers AI embeddings request timed out after 30 seconds while contacting api.cloudflare.com.',
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
			'account-123',
			'workers-token',
			'@cf/qwen/qwen3-embedding-0.6b'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'Unexpected Cloudflare Workers AI embeddings validation response format.',
			$result->get_error_message()
		);
	}
}
