<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Embeddings\EmbeddingClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class CloudflareWorkersAIEmbeddingTest extends TestCase {


	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
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

	public function test_selected_workers_ai_embeddings_do_not_fallback_to_openai_native_when_incomplete(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => '',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_openai_native_api_key'           => 'native-key',
			'flavor_agent_openai_native_embedding_model'   => 'text-embedding-3-large',
		];

		$config = Provider::embedding_configuration();

		$this->assertSame( 'cloudflare_workers_ai', $config['provider'] );
		$this->assertFalse( $config['configured'] );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
			$config['url']
		);
		$this->assertNotSame( 'https://api.openai.com/v1/embeddings', $config['url'] );
	}

	public function test_saved_non_cloudflare_provider_uses_workers_ai_for_runtime_embeddings(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                        => 'anthropic',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
		];

		$config = Provider::embedding_configuration();

		$this->assertSame( 'cloudflare_workers_ai', $config['provider'] );
		$this->assertSame( '@cf/qwen/qwen3-embedding-0.6b', $config['model'] );
		$this->assertFalse( $config['configured'] );
	}

	public function test_embedding_provider_contract_is_workers_ai_only(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                        => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
		];

		$this->assertSame(
			[
				'cloudflare_workers_ai' => 'Cloudflare Workers AI',
			],
			Provider::direct_choices()
		);
		$this->assertSame( 'cloudflare_workers_ai', Provider::get() );
		$this->assertSame( 'cloudflare_workers_ai', Provider::embedding_configuration()['provider'] );
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

	public function test_embedding_signature_ignores_account_id_for_workers_ai_vector_compatibility(): void {
		$first  = EmbeddingClient::build_signature_for_dimension(
			1024,
			[
				'provider' => 'cloudflare_workers_ai',
				'model'    => '@cf/qwen/qwen3-embedding-0.6b',
				'endpoint' => 'https://api.cloudflare.com/client/v4/accounts/account-one/ai/v1',
			]
		);
		$second = EmbeddingClient::build_signature_for_dimension(
			1024,
			[
				'provider' => 'cloudflare_workers_ai',
				'model'    => '@cf/qwen/qwen3-embedding-0.6b',
				'endpoint' => 'https://api.cloudflare.com/client/v4/accounts/account-two/ai/v1',
			]
		);

		$this->assertSame( $first['signature_hash'], $second['signature_hash'] );
	}

	public function test_saved_azure_provider_defaults_to_workers_ai_embeddings(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'azure_openai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

		$config = Provider::embedding_configuration();

		$this->assertSame( 'cloudflare_workers_ai', $config['provider'] );
		$this->assertTrue( $config['configured'] );
		$this->assertSame(
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
			'@cf/qwen/qwen3-embedding-0.6b'
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

	public function test_embed_batch_chunks_workers_ai_requests_at_model_limit(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

		$inputs = array_map(
			static fn ( int $index ): string => 'pattern text ' . $index,
			range( 1, 65 )
		);

		foreach ( [ 32, 32, 1 ] as $count ) {
			WordPressTestState::$remote_post_responses[] = [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'data' => array_map(
							static fn ( int $index ): array => [ 'embedding' => [ (float) $index, (float) ( $index + 1 ) ] ],
							range( 1, $count )
						),
					]
				),
			];
		}

		$result = EmbeddingClient::embed_batch( $inputs );

		$this->assertCount( 65, $result );
		$this->assertCount( 3, WordPressTestState::$remote_post_calls );
		$first_body  = json_decode( WordPressTestState::$remote_post_calls[0]['args']['body'], true );
		$second_body = json_decode( WordPressTestState::$remote_post_calls[1]['args']['body'], true );
		$third_body  = json_decode( WordPressTestState::$remote_post_calls[2]['args']['body'], true );
		$this->assertCount( 32, $first_body['input'] );
		$this->assertCount( 32, $second_body['input'] );
		$this->assertCount( 1, $third_body['input'] );
	}

	public function test_embedding_validation_ignores_saved_connector_provider_and_uses_workers_ai_options(): void {
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
		WordPressTestState::$options = [
			'flavor_agent_openai_provider' => 'anthropic',
		];

		$result = EmbeddingClient::validate_configuration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Cloudflare Workers AI', $result->get_error_message() );
		$this->assertSame(
			'Cloudflare Workers AI embedding credentials are not configured. Go to Settings > Flavor Agent.',
			$result->get_error_message()
		);
	}
}
