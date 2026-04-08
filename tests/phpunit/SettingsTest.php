<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Settings;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$_POST = [];
		$_GET  = [];
		$this->reset_validation_state();
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		$this->reset_validation_state();

		parent::tearDown();
	}

	public function test_sanitize_cloudflare_settings_skips_remote_validation_when_credentials_are_unchanged(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			'flavor_agent_azure_openai_endpoint'           => 'https://example.openai.azure.com/',
		];

		$this->assertSame( 'account-123', Settings::sanitize_cloudflare_account_id( 'account-123' ) );
		$this->assertSame( 'wp-dev-docs', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs' ) );
		$this->assertSame( 'token-xyz', Settings::sanitize_cloudflare_api_token( 'token-xyz' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_azure_settings_skip_remote_validation_when_credentials_are_unchanged(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
			'flavor_agent_azure_chat_deployment'      => 'chat-deployment',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
			'flavor_agent_azure_chat_deployment'      => 'chat-deployment',
		];

		$this->assertSame(
			'https://example.openai.azure.com/',
			Settings::sanitize_azure_openai_endpoint( 'https://example.openai.azure.com/' )
		);
		$this->assertSame( 'azure-key', Settings::sanitize_azure_openai_key( 'azure-key' ) );
		$this->assertSame(
			'embed-deployment',
			Settings::sanitize_azure_embedding_deployment( 'embed-deployment' )
		);
		$this->assertSame(
			'chat-deployment',
			Settings::sanitize_azure_chat_deployment( 'chat-deployment' )
		);
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_azure_settings_accept_verified_values_and_validate_once_per_save(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://old.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'old-key',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
			'flavor_agent_azure_chat_deployment'      => 'old-chat',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://new.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'new-key',
			'flavor_agent_azure_embedding_deployment' => 'new-embed',
			'flavor_agent_azure_chat_deployment'      => 'new-chat',
		];

		WordPressTestState::$remote_post_responses = [
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
						'output' => [
							[
								'content' => [
									[
										'text' => 'ok',
									],
								],
							],
						],
					]
				),
			],
		];

		$this->assertSame(
			'https://new.openai.azure.com/',
			Settings::sanitize_azure_openai_endpoint( 'https://new.openai.azure.com/' )
		);
		$this->assertSame( 'new-key', Settings::sanitize_azure_openai_key( 'new-key' ) );
		$this->assertSame( 'new-embed', Settings::sanitize_azure_embedding_deployment( 'new-embed' ) );
		$this->assertSame( 'new-chat', Settings::sanitize_azure_chat_deployment( 'new-chat' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://new.openai.azure.com/openai/v1/embeddings',
			WordPressTestState::$remote_post_calls[0]['url']
		);
		$this->assertSame(
			'https://new.openai.azure.com/openai/v1/responses',
			WordPressTestState::$remote_post_calls[1]['url']
		);
	}

	public function test_sanitize_azure_settings_revert_invalid_values_and_report_one_error(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://old.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'old-key',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
			'flavor_agent_azure_chat_deployment'      => 'old-chat',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://bad.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'bad-key',
			'flavor_agent_azure_embedding_deployment' => 'bad-embed',
			'flavor_agent_azure_chat_deployment'      => 'bad-chat',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 403,
			],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Azure authentication failed',
					],
				]
			),
		];

		$this->assertSame(
			'https://old.openai.azure.com/',
			Settings::sanitize_azure_openai_endpoint( 'https://bad.openai.azure.com/' )
		);
		$this->assertSame( 'old-key', Settings::sanitize_azure_openai_key( 'bad-key' ) );
		$this->assertSame( 'old-embed', Settings::sanitize_azure_embedding_deployment( 'bad-embed' ) );
		$this->assertSame( 'old-chat', Settings::sanitize_azure_chat_deployment( 'bad-chat' ) );
		$this->assertCount( 1, WordPressTestState::$settings_errors );
		$this->assertSame( 'Azure authentication failed', WordPressTestState::$settings_errors[0]['message'] );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_azure_settings_allow_partial_credentials_without_remote_validation(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
			'flavor_agent_azure_chat_deployment'      => 'chat-deployment',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => '',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
			'flavor_agent_azure_chat_deployment'      => 'chat-deployment',
		];

		$this->assertSame( '', Settings::sanitize_azure_openai_endpoint( '' ) );
		$this->assertSame( 'azure-key', Settings::sanitize_azure_openai_key( 'azure-key' ) );
		$this->assertSame(
			'embed-deployment',
			Settings::sanitize_azure_embedding_deployment( 'embed-deployment' )
		);
		$this->assertSame(
			'chat-deployment',
			Settings::sanitize_azure_chat_deployment( 'chat-deployment' )
		);
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_openai_native_settings_skip_remote_validation_when_credentials_are_unchanged(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_openai_native_chat_model'      => 'gpt-5.4',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_openai_native_chat_model'      => 'gpt-5.4',
		];

		$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( 'native-key', Settings::sanitize_openai_native_api_key( 'native-key' ) );
		$this->assertSame(
			'text-embedding-3-large',
			Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' )
		);
		$this->assertSame( 'gpt-5.4', Settings::sanitize_openai_native_chat_model( 'gpt-5.4' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_openai_provider_accepts_a_registered_connector_provider(): void {
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

		$this->assertSame( 'anthropic', Settings::sanitize_openai_provider( 'anthropic' ) );
	}

	public function test_sanitize_openai_native_settings_accept_verified_values_and_validate_once_per_save(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'azure_openai',
			'flavor_agent_openai_native_api_key'         => 'old-key',
			'flavor_agent_openai_native_embedding_model' => 'old-embed',
			'flavor_agent_openai_native_chat_model'      => 'old-chat',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'new-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_openai_native_chat_model'      => 'gpt-5.4',
		];

		WordPressTestState::$remote_post_responses = [
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
						'output_text' => 'ok',
					]
				),
			],
		];

		$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( 'new-key', Settings::sanitize_openai_native_api_key( 'new-key' ) );
		$this->assertSame(
			'text-embedding-3-large',
			Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' )
		);
		$this->assertSame( 'gpt-5.4', Settings::sanitize_openai_native_chat_model( 'gpt-5.4' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
		$this->assertSame( 'https://api.openai.com/v1/embeddings', WordPressTestState::$remote_post_calls[0]['url'] );
		$this->assertSame( 'https://api.openai.com/v1/responses', WordPressTestState::$remote_post_calls[1]['url'] );
		$this->assertSame(
			'Bearer new-key',
			WordPressTestState::$remote_post_calls[0]['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_sanitize_openai_native_settings_revert_invalid_values_and_report_one_error(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'old-key',
			'flavor_agent_openai_native_embedding_model' => 'old-embed',
			'flavor_agent_openai_native_chat_model'      => 'old-chat',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'bad-key',
			'flavor_agent_openai_native_embedding_model' => 'bad-embed',
			'flavor_agent_openai_native_chat_model'      => 'bad-chat',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 401,
			],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'OpenAI authentication failed',
					],
				]
			),
		];

		$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( 'old-key', Settings::sanitize_openai_native_api_key( 'bad-key' ) );
		$this->assertSame( 'old-embed', Settings::sanitize_openai_native_embedding_model( 'bad-embed' ) );
		$this->assertSame( 'old-chat', Settings::sanitize_openai_native_chat_model( 'bad-chat' ) );
		$this->assertCount( 1, WordPressTestState::$settings_errors );
		$this->assertSame( 'OpenAI authentication failed', WordPressTestState::$settings_errors[0]['message'] );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_openai_native_settings_validate_with_connector_key_when_plugin_key_is_blank(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'azure_openai',
			'connectors_ai_openai_api_key'               => 'connector-key',
			'flavor_agent_openai_native_api_key'         => '',
			'flavor_agent_openai_native_embedding_model' => 'old-embed',
			'flavor_agent_openai_native_chat_model'      => 'old-chat',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => '',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_openai_native_chat_model'      => 'gpt-5.4',
		];

		WordPressTestState::$remote_post_responses = [
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
						'output_text' => 'ok',
					]
				),
			],
		];

		$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( '', Settings::sanitize_openai_native_api_key( '' ) );
		$this->assertSame(
			'text-embedding-3-large',
			Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' )
		);
		$this->assertSame( 'gpt-5.4', Settings::sanitize_openai_native_chat_model( 'gpt-5.4' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 2, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'Bearer connector-key',
			WordPressTestState::$remote_post_calls[0]['args']['headers']['Authorization'] ?? null
		);
		$this->assertSame(
			'Bearer connector-key',
			WordPressTestState::$remote_post_calls[1]['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_sanitize_qdrant_settings_skip_remote_validation_when_credentials_are_unchanged(): void {
		WordPressTestState::$options = [
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];
		$_POST                       = [
			'option_page'             => 'flavor_agent_settings',
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];

		$this->assertSame(
			'https://example.cloud.qdrant.io:6333',
			Settings::sanitize_qdrant_url( 'https://example.cloud.qdrant.io:6333' )
		);
		$this->assertSame( 'qdrant-key', Settings::sanitize_qdrant_key( 'qdrant-key' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_sanitize_qdrant_settings_accept_verified_values_and_validate_once(): void {
		WordPressTestState::$options = [
			'flavor_agent_qdrant_url' => 'https://old.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'old-key',
		];
		$_POST                       = [
			'option_page'             => 'flavor_agent_settings',
			'flavor_agent_qdrant_url' => 'https://new.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'new-key',
		];

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

		$this->assertSame(
			'https://new.cloud.qdrant.io:6333',
			Settings::sanitize_qdrant_url( 'https://new.cloud.qdrant.io:6333' )
		);
		$this->assertSame( 'new-key', Settings::sanitize_qdrant_key( 'new-key' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
		$this->assertSame(
			'https://new.cloud.qdrant.io:6333/collections',
			WordPressTestState::$last_remote_get['url']
		);
	}

	public function test_sanitize_qdrant_settings_revert_invalid_values_and_report_one_error(): void {
		WordPressTestState::$options = [
			'flavor_agent_qdrant_url' => 'https://old.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'old-key',
		];
		$_POST                       = [
			'option_page'             => 'flavor_agent_settings',
			'flavor_agent_qdrant_url' => 'https://bad.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'bad-key',
		];

		WordPressTestState::$remote_get_response = [
			'response' => [
				'code' => 401,
			],
			'body'     => wp_json_encode(
				[
					'status' => [
						'error' => 'Invalid Qdrant API key',
					],
				]
			),
		];

		$this->assertSame(
			'https://old.cloud.qdrant.io:6333',
			Settings::sanitize_qdrant_url( 'https://bad.cloud.qdrant.io:6333' )
		);
		$this->assertSame( 'old-key', Settings::sanitize_qdrant_key( 'bad-key' ) );
		$this->assertCount( 1, WordPressTestState::$settings_errors );
		$this->assertSame( 'Invalid Qdrant API key', WordPressTestState::$settings_errors[0]['message'] );
		$this->assertCount( 1, WordPressTestState::$remote_get_calls );
	}

	public function test_sanitize_qdrant_settings_allow_partial_credentials_without_remote_validation(): void {
		WordPressTestState::$options = [
			'flavor_agent_qdrant_url' => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];
		$_POST                       = [
			'option_page'             => 'flavor_agent_settings',
			'flavor_agent_qdrant_url' => '',
			'flavor_agent_qdrant_key' => 'qdrant-key',
		];

		$this->assertSame( '', Settings::sanitize_qdrant_url( '' ) );
		$this->assertSame( 'qdrant-key', Settings::sanitize_qdrant_key( 'qdrant-key' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
	}

	public function test_sanitize_cloudflare_settings_accepts_verified_values_and_validates_once(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'probe-chunk',
								'item' => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];

		$this->assertSame( 'account-123', Settings::sanitize_cloudflare_account_id( 'account-123' ) );
		$this->assertSame( 'wp-dev-docs', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs' ) );
		$this->assertSame( 'token-xyz', Settings::sanitize_cloudflare_api_token( 'token-xyz' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai-search/instances/wp-dev-docs/search',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_cloudflare_settings_schedules_prewarm_on_first_successful_save(): void {
		$_POST = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'probe-chunk',
								'item' => [
									'key'      => 'developer.wordpress.org/block-editor/reference-guides/block-api/block-supports',
									'metadata' => [],
								],
								'text' => "---\nsource_url: \"https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/\"\n---\nUse block supports to expose design tools.",
							],
						],
					],
				]
			),
		];

		$this->assertSame( 'account-123', Settings::sanitize_cloudflare_account_id( 'account-123' ) );
		$this->assertSame( 'wp-dev-docs', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs' ) );
		$this->assertSame( 'token-xyz', Settings::sanitize_cloudflare_api_token( 'token-xyz' ) );
		$this->assertArrayHasKey( AISearchClient::PREWARM_CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_ai_search_account_id', WordPressTestState::$options );
	}

	public function test_sanitize_cloudflare_settings_reverts_invalid_values_and_reports_error_once(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-new',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-new',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-new',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 403,
			],
			'body'     => wp_json_encode(
				[
					'errors' => [
						[
							'message' => 'Authentication error',
						],
					],
				]
			),
		];

		$this->assertSame( 'account-old', Settings::sanitize_cloudflare_account_id( 'account-new' ) );
		$this->assertSame( 'wp-dev-docs-old', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs-new' ) );
		$this->assertSame( 'token-old', Settings::sanitize_cloudflare_api_token( 'token-new' ) );
		$this->assertCount( 1, WordPressTestState::$settings_errors );
		$this->assertSame(
			'Authentication error',
			WordPressTestState::$settings_errors[0]['message']
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_cloudflare_settings_allows_partial_credentials_without_remote_validation(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => '',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];

		$this->assertSame( '', Settings::sanitize_cloudflare_account_id( '' ) );
		$this->assertSame( 'wp-dev-docs-old', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs-old' ) );
		$this->assertSame( 'token-old', Settings::sanitize_cloudflare_api_token( 'token-old' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_cloudflare_settings_reverts_incompatible_values_and_reports_error_once(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-old',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-old',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-old',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-new',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs-new',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-new',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'result' => [
						'chunks' => [
							[
								'id'   => 'private-chunk',
								'item' => [
									'key'      => 'internal/wiki/theme-roadmap',
									'metadata' => [
										'url' => 'https://intranet.example.com/wiki/theme-roadmap',
									],
								],
								'text' => 'Private roadmap content that should never reach editor prompts.',
							],
						],
					],
				]
			),
		];

		$this->assertSame( 'account-old', Settings::sanitize_cloudflare_account_id( 'account-new' ) );
		$this->assertSame( 'wp-dev-docs-old', Settings::sanitize_cloudflare_instance_id( 'wp-dev-docs-new' ) );
		$this->assertSame( 'token-old', Settings::sanitize_cloudflare_api_token( 'token-new' ) );
		$this->assertCount( 1, WordPressTestState::$settings_errors );
		$this->assertSame(
			'Cloudflare AI Search validation could not confirm trusted developer.wordpress.org content from this instance. Use the official WordPress developer docs index before saving these credentials.',
			WordPressTestState::$settings_errors[0]['message']
		);
		$this->assertCount( 0, WordPressTestState::$remote_get_calls );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_render_settings_notices_displays_general_and_plugin_messages_after_redirect(): void {
		$_GET = [
			'settings-updated' => 'true',
		];

		WordPressTestState::$transients['settings_errors'] = [
			[
				'setting' => 'general',
				'code'    => 'settings_updated',
				'message' => 'Settings saved.',
				'type'    => 'success',
			],
			[
				'setting' => 'flavor_agent_settings',
				'code'    => 'flavor_agent_cloudflare_ai_search_validation',
				'message' => 'Authentication error',
				'type'    => 'error',
			],
		];

		ob_start();
		$method = new ReflectionMethod( Settings::class, 'render_settings_notices' );
		$method->setAccessible( true );
		$method->invoke( null );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Settings saved.', $output );
		$this->assertStringContainsString( 'Authentication error', $output );
		$this->assertArrayNotHasKey( 'settings_errors', WordPressTestState::$transients );
	}

	public function test_render_openai_native_section_reports_effective_connector_key_source(): void {
		WordPressTestState::$connectors = [
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
		WordPressTestState::$options    = [
			'connectors_ai_openai_api_key' => 'connector-key',
		];

		ob_start();
		Settings::render_openai_native_section();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Current effective OpenAI key source:',
			$output
		);
		$this->assertStringContainsString(
			'Settings &gt; Connectors',
			$output
		);
	}

	public function test_render_select_field_lists_configured_connector_providers(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME => 'anthropic',
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
			'google'    => [
				'name'           => 'Google',
				'description'    => 'Google connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_google_api_key',
				],
			],
		];

		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
			'google'    => false,
		];

		ob_start();
		Settings::render_select_field(
			[
				'option'  => Provider::OPTION_NAME,
				'choices' => Provider::choices( 'anthropic' ),
			]
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Anthropic (Settings &gt; Connectors)', $output );
		$this->assertStringContainsString( 'value="anthropic" selected=', $output );
		$this->assertStringNotContainsString( 'Google (Settings &gt; Connectors)', $output );
	}

	public function test_render_text_field_outputs_numeric_constraints(): void {
		ob_start();
		Settings::render_text_field(
			[
				'option'  => 'flavor_agent_pattern_recommendation_threshold',
				'type'    => 'number',
				'default' => '0.3',
				'step'    => '0.01',
				'min'     => '0',
				'max'     => '1',
			]
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="number"', $output );
		$this->assertStringContainsString( 'value="0.3"', $output );
		$this->assertStringContainsString( 'step="0.01"', $output );
		$this->assertStringContainsString( 'min="0"', $output );
		$this->assertStringContainsString( 'max="1"', $output );
	}

	public function test_register_contextual_help_uses_native_wp_screen_help_tabs(): void {
		$screen = new \WP_Screen();
		WordPressTestState::$current_screen = $screen;

		Settings::register_contextual_help();

		$this->assertCount( 3, $screen->help_tabs );
		$this->assertSame( 'flavor-agent-overview', $screen->help_tabs[0]['id'] );
		$this->assertSame( 'Overview', $screen->help_tabs[0]['title'] );
		$this->assertStringContainsString( 'This screen keeps inline copy short', $screen->help_tabs[0]['content'] );
		$this->assertStringContainsString( 'Configure Chat Provider first', $screen->help_tabs[0]['content'] );
		$this->assertSame( 'flavor-agent-configuration', $screen->help_tabs[1]['id'] );
		$this->assertStringContainsString( 'Settings &gt; Connectors', $screen->help_tabs[1]['content'] );
		$this->assertStringContainsString( 'built-in public developer.wordpress.org endpoint by default', $screen->help_tabs[1]['content'] );
		$this->assertStringContainsString( 'Cloudflare override fields are only for older installs or explicit custom-endpoint use.', $screen->help_tabs[1]['content'] );
		$this->assertSame( 'flavor-agent-troubleshooting', $screen->help_tabs[2]['id'] );
		$this->assertStringContainsString( 'Guidelines import fills the form first', $screen->help_tabs[2]['content'] );
		$this->assertStringContainsString( 'Quick Links', $screen->help_sidebar );
		$this->assertStringContainsString( 'options-connectors.php', $screen->help_sidebar );
		$this->assertStringContainsString( 'flavor-agent-activity', $screen->help_sidebar );
	}

	public function test_render_page_moves_setup_guidance_into_wp_help(): void {
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Flavor Agent Settings',
			$output
		);
		$this->assertStringContainsString(
			'Use Help for setup reference and troubleshooting.',
			$output
		);
		$this->assertStringContainsString( 'Required', $output );
		$this->assertStringContainsString( 'Choose the chat path Flavor Agent should prefer.', $output );
		$this->assertStringContainsString( 'Optional', $output );
		$this->assertStringContainsString( 'Add vector search for pattern recommendations.', $output );
		$this->assertStringContainsString( 'Ground responses with developer.wordpress.org docs.', $output );
		$this->assertStringContainsString( 'Store plugin-owned site, writing, image, and block guidance.', $output );
		$this->assertStringNotContainsString( 'Set up chat first.', $output );
		$this->assertStringNotContainsString( 'Optional second step for vector-based pattern recommendations.', $output );
		$this->assertStringNotContainsString( 'Recent Activity', $output );
		$this->assertStringNotContainsString( 'Where To Configure What', $output );
		$this->assertStringNotContainsString( 'Use Connectors for shared provider credentials.', $output );
		$this->assertStringContainsString(
			'Sync Pattern Catalog',
			$output
		);
		$this->assertStringContainsString(
			'name="flavor_agent_settings_feedback_key"',
			$output
		);
		$this->assertStringContainsString(
			'name="_wp_http_referer"',
			$output
		);
	}

	public function test_render_page_keeps_cloudflare_override_controls_available(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id'  => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'   => 'token-xyz',
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Cloudflare Override',
			$output
		);
		$this->assertStringContainsString(
			'name="flavor_agent_cloudflare_ai_search_account_id"',
			$output
		);
		$this->assertStringContainsString(
			'name="flavor_agent_cloudflare_ai_search_instance_id"',
			$output
		);
		$this->assertStringContainsString(
			'name="flavor_agent_cloudflare_ai_search_api_token"',
			$output
		);
		$this->assertStringContainsString(
			'Older installs or explicit custom-endpoint overrides only. Leave these blank to use the built-in public docs endpoint.',
			$output
		);
		$this->assertStringContainsString(
			'Saved override values are present. Clear all three fields to stop using the override.',
			$output
		);
		$this->assertStringContainsString(
			'Optional override. Cloudflare account ID for older installs or custom endpoints.',
			$output
		);
		$this->assertStringNotContainsString( 'Legacy override only.', $output );
		$this->assertStringNotContainsString( 'Required for docs grounding.', $output );
	}

	public function test_render_page_consumes_request_scoped_feedback_only_for_the_matching_user(): void {
		WordPressTestState::$current_user_id = 1;
		$_POST                              = [
			'option_page'                         => 'flavor_agent_settings',
			'flavor_agent_settings_feedback_key' => 'token-user-1',
		];

		Settings::sanitize_openai_provider( 'openai_native' );

		$_POST = [];
		$_GET  = [
			'settings-updated'                    => 'true',
			'flavor_agent_settings_feedback_key' => 'token-user-1',
		];

		WordPressTestState::$current_user_id = 2;
		ob_start();
		Settings::render_page();
		$other_user_output = (string) ob_get_clean();

		$this->assertStringNotContainsString(
			'Chat provider saved.',
			$other_user_output
		);
		$this->assertNotEmpty( WordPressTestState::$transients );

		WordPressTestState::$current_user_id = 1;
		ob_start();
		Settings::render_page();
		$matching_user_output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Chat provider saved.',
			$matching_user_output
		);
		$this->assertSame( [], WordPressTestState::$transients );
	}

	public function test_render_page_includes_guidelines_controls(): void {
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '4. Guidelines', $output );
		$this->assertStringContainsString( 'name="flavor_agent_guideline_site"', $output );
		$this->assertStringContainsString( 'name="flavor_agent_guideline_copy"', $output );
		$this->assertStringContainsString( 'Block Guidelines', $output );
		$this->assertStringContainsString( 'Import JSON', $output );
		$this->assertStringContainsString( 'Export JSON', $output );
		$this->assertStringContainsString( 'Import fills the form. Save Changes to persist.', $output );
		$this->assertStringNotContainsString(
			'Store plugin-owned guidance that Flavor Agent can rely on without coupling to Gutenberg experiments or another plugin’s data model.',
			$output
		);
	}

	public function test_sanitize_guideline_copy_marks_guidelines_feedback_and_sanitizes_text(): void {
		$_POST = [
			'option_page'                         => 'flavor_agent_settings',
			'flavor_agent_settings_feedback_key' => 'guidelines-copy',
		];

		$this->assertSame(
			'Use active voice.',
			Settings::sanitize_guideline_copy( ' <strong>Use active voice.</strong> ' )
		);

		$feedback = array_values( WordPressTestState::$transients )[0] ?? [];

		$this->assertTrue( (bool) ( $feedback['changed_sections']['guidelines'] ?? false ) );
	}

	public function test_sanitize_guideline_blocks_only_marks_feedback_for_changed_array_values(): void {
		WordPressTestState::$options = [
			Guidelines::OPTION_BLOCKS => [
				'core/paragraph' => 'Use short paragraphs.',
			],
		];
		$_POST                       = [
			'option_page'                         => 'flavor_agent_settings',
			'flavor_agent_settings_feedback_key' => 'guidelines-blocks',
		];

		$this->assertSame(
			[
				'core/paragraph' => 'Use short paragraphs.',
			],
			Settings::sanitize_guideline_blocks(
				wp_json_encode(
					[
						'core/paragraph' => [
							'guidelines' => 'Use short paragraphs.',
						],
					]
				)
			)
		);
		$this->assertSame( [], WordPressTestState::$transients );

		$this->assertSame(
			[
				'core/paragraph' => 'Use short paragraphs and clear CTAs.',
			],
			Settings::sanitize_guideline_blocks(
				wp_json_encode(
					[
						'core/paragraph' => [
							'guidelines' => 'Use short paragraphs and clear CTAs.',
						],
					]
				)
			)
		);

		$feedback = array_values( WordPressTestState::$transients )[0] ?? [];

		$this->assertTrue( (bool) ( $feedback['changed_sections']['guidelines'] ?? false ) );
	}

	public function test_sanitize_azure_reasoning_effort_accepts_xhigh(): void {
		$this->assertSame( 'xhigh', Settings::sanitize_azure_reasoning_effort( 'xhigh' ) );
		$this->assertSame( 'medium', Settings::sanitize_azure_reasoning_effort( 'invalid' ) );
	}

	public function test_get_pattern_sync_reason_label_handles_collection_rebuild_reasons(): void {
		$method = new ReflectionMethod( Settings::class, 'get_pattern_sync_reason_label' );
		$method->setAccessible( true );

		$this->assertSame(
			'Pattern index collection is missing and needs a rebuild.',
			$method->invoke( null, 'collection_missing' )
		);
		$this->assertSame(
			'Pattern index collection vector size no longer matches the active embedding configuration.',
			$method->invoke( null, 'collection_size_mismatch' )
		);
	}

	public function test_get_docs_overview_status_reports_retrying_from_runtime_grounding_state(): void {
		$method = new ReflectionMethod( Settings::class, 'get_docs_overview_status' );
		$method->setAccessible( true );

		$result = $method->invoke(
			null,
			[
				'docs_configured'         => true,
				'prewarm_state'           => [
					'status' => 'ok',
				],
				'runtime_docs_grounding' => [
					'status' => 'retrying',
				],
			]
		);

		$this->assertSame(
			[
				'label' => 'Retrying',
				'tone'  => 'warning',
			],
			$result
		);
	}

	public function test_render_page_shows_runtime_grounding_diagnostics(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
			'flavor_agent_docs_runtime_state'              => [
				'lastSearchAt'           => '2026-04-08 10:00:00',
				'lastSearchMode'         => 'async',
				'lastResultCount'        => 0,
				'lastTrustedSuccessAt'   => '2026-04-08 09:45:00',
				'lastTrustedSuccessMode' => 'foreground',
				'lastServedAt'           => '2026-04-08 09:50:00',
				'lastServedMode'         => 'cache',
				'lastFallbackType'       => 'generic',
				'lastErrorAt'            => '2026-04-08 10:00:00',
				'lastErrorMode'          => 'async',
				'lastErrorCode'          => 'http_request_failed',
				'lastErrorMessage'       => 'Cloudflare timed out.',
			],
			'flavor_agent_docs_warm_queue'                => [
				[
					'query'            => 'navigation footer guidance',
					'entityKey'        => 'core/navigation',
					'familyContext'    => [
						'surface' => 'block',
					],
					'maxResults'       => 4,
					'attempts'         => 1,
					'nextAttemptAt'    => time() + 60,
					'lastErrorAt'      => '2026-04-08 10:00:00',
					'lastErrorCode'    => 'http_request_failed',
					'lastErrorMessage' => 'Cloudflare timed out.',
				],
			],
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Runtime Grounding', $output );
		$this->assertStringContainsString( 'Retrying', $output );
		$this->assertStringContainsString( 'Warm queue: 1 pending.', $output );
		$this->assertStringContainsString( 'Last trusted success: 2026-04-08 09:45:00 UTC via foreground warm', $output );
		$this->assertStringContainsString( 'Last error (async warm): Cloudflare timed out.', $output );
	}

	private function reset_validation_state(): void {
		$azure_state = new ReflectionProperty( Settings::class, 'azure_validation_state' );
		$azure_state->setAccessible( true );
		$azure_state->setValue( null, null );

		$azure_reported = new ReflectionProperty( Settings::class, 'azure_validation_error_reported' );
		$azure_reported->setAccessible( true );
		$azure_reported->setValue( null, false );

		$native_state = new ReflectionProperty( Settings::class, 'native_openai_validation_state' );
		$native_state->setAccessible( true );
		$native_state->setValue( null, null );

		$native_reported = new ReflectionProperty( Settings::class, 'native_openai_validation_error_reported' );
		$native_reported->setAccessible( true );
		$native_reported->setValue( null, false );

		$qdrant_state = new ReflectionProperty( Settings::class, 'qdrant_validation_state' );
		$qdrant_state->setAccessible( true );
		$qdrant_state->setValue( null, null );

		$qdrant_reported = new ReflectionProperty( Settings::class, 'qdrant_validation_error_reported' );
		$qdrant_reported->setAccessible( true );
		$qdrant_reported->setValue( null, false );

		$state = new ReflectionProperty( Settings::class, 'cloudflare_validation_state' );
		$state->setAccessible( true );
		$state->setValue( null, null );

		$reported = new ReflectionProperty( Settings::class, 'cloudflare_validation_error_reported' );
		$reported->setAccessible( true );
		$reported->setValue( null, false );
	}
}
