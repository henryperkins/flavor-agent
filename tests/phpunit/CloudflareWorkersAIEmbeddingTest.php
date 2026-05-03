<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class CloudflareWorkersAIEmbeddingTest extends TestCase {

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

	public function test_provider_builds_workers_ai_embedding_configuration(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

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

	public function test_workers_ai_is_not_used_as_implicit_embedding_fallback(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => Provider::AZURE,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

		$config = Provider::embedding_configuration();

		$this->assertSame( Provider::AZURE, $config['provider'] );
		$this->assertFalse( $config['configured'] );
		$this->assertNotSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
			$config['url']
		);
	}

	public function test_workers_ai_embedding_validation_uses_submitted_values(): void {
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

		$result = EmbeddingClient::validate_configuration(
			'account-123',
			'token-xyz',
			'@cf/qwen/qwen3-embedding-0.6b',
			'cloudflare_workers_ai'
		);

		$this->assertSame( 3, $result['dimension'] );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
			WordPressTestState::$remote_post_calls[0]['url']
		);
		$this->assertSame( 'Bearer token-xyz', WordPressTestState::$remote_post_calls[0]['args']['headers']['Authorization'] );
	}

	public function test_workers_ai_embedding_batch_reuses_existing_vector_parser(): void {
		WordPressTestState::$options                 = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];
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

		$result       = EmbeddingClient::embed_batch( [ 'alpha', 'beta' ] );
		$request_body = json_decode( WordPressTestState::$remote_post_calls[0]['args']['body'], true );

		$this->assertSame( [ [ 0.1, 0.2 ], [ 0.3, 0.4 ] ], $result );
		$this->assertSame( '@cf/qwen/qwen3-embedding-0.6b', $request_body['model'] ?? '' );
	}

	public function test_connector_embedding_error_mentions_workers_ai_option(): void {
		WordPressTestState::$connectors = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];

		$result = EmbeddingClient::validate_configuration( null, null, null, 'anthropic' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Cloudflare Workers AI', $result->get_error_message() );
	}
}
