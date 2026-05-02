<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\Settings\Assets;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Admin\Settings\Feedback;
use FlavorAgent\Admin\Settings\Page;
use FlavorAgent\Admin\Settings\State;
use FlavorAgent\Admin\Settings\Utils;
use FlavorAgent\Admin\Settings\Validation;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Settings;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	private string|false $previous_openai_api_key;

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->previous_openai_api_key = getenv( 'OPENAI_API_KEY' );
		putenv( 'OPENAI_API_KEY' );
		$_POST = [];
		$_GET  = [];
		$this->reset_validation_state();
	}

	protected function tearDown(): void {
		if ( false === $this->previous_openai_api_key ) {
			putenv( 'OPENAI_API_KEY' );
		} else {
			putenv( 'OPENAI_API_KEY=' . $this->previous_openai_api_key );
		}

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
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
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
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_azure_settings_accept_verified_values_and_validate_once_per_save(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://old.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'old-key',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://new.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'new-key',
			'flavor_agent_azure_embedding_deployment' => 'new-embed',
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
		];

		$this->assertSame(
			'https://new.openai.azure.com/',
			Settings::sanitize_azure_openai_endpoint( 'https://new.openai.azure.com/' )
		);
		$this->assertSame( 'new-key', Settings::sanitize_azure_openai_key( 'new-key' ) );
		$this->assertSame( 'new-embed', Settings::sanitize_azure_embedding_deployment( 'new-embed' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://new.openai.azure.com/openai/v1/embeddings',
			WordPressTestState::$remote_post_calls[0]['url']
		);
	}

	public function test_sanitize_azure_settings_revert_invalid_values_and_report_fallback_notices(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://old.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'old-key',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://bad.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'bad-key',
			'flavor_agent_azure_embedding_deployment' => 'bad-embed',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 403,
			],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Azure authentication failed for bad-key',
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
		$this->assertSame(
			[
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_azure_validation',
					'message' => 'Azure validation failed. Check the endpoint, API key, and embedding deployment, then try again.',
					'type'    => 'error',
				],
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_azure_validation_preserved',
					'message' => 'We kept your previous Azure settings because validation failed.',
					'type'    => 'warning',
				],
			],
			WordPressTestState::$settings_errors
		);
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_azure_settings_request_scopes_validation_feedback_when_feedback_key_is_present(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://old.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'old-key',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_settings_feedback_key'      => 'azure-error-scope',
			'flavor_agent_azure_openai_endpoint'      => 'https://bad.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'bad-key',
			'flavor_agent_azure_embedding_deployment' => 'bad-embed',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 403,
			],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Azure authentication failed for bad-key',
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
		$this->assertSame( [], WordPressTestState::$settings_errors );

		$storage_key = Feedback::get_storage_key( 'azure-error-scope' );
		$feedback    = WordPressTestState::$transients[ $storage_key ] ?? [];

		$this->assertSame( 'chat', $feedback['focus_section'] ?? '' );
		$this->assertSame(
			[
				[
					'tone'    => 'error',
					'message' => 'Azure validation failed. Check the endpoint, API key, and embedding deployment, then try again.',
				],
				[
					'tone'    => 'warning',
					'message' => 'We kept your previous Azure settings because validation failed.',
				],
			],
			$feedback['messages']['chat'] ?? []
		);
		$this->assertStringNotContainsString( 'bad-key', (string) wp_json_encode( $feedback ) );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_blank_password_submission_preserves_saved_azure_key_when_companion_values_remain(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'saved-azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => '',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];

		$this->assertSame( 'saved-azure-key', Settings::sanitize_azure_openai_key( '' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_blank_password_submission_can_clear_secret_when_companion_values_are_blank(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'saved-azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => '',
			'flavor_agent_azure_openai_key'           => '',
			'flavor_agent_azure_embedding_deployment' => '',
		];

		$this->assertSame( '', Settings::sanitize_azure_openai_key( '' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_azure_settings_allow_partial_credentials_without_remote_validation(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => '',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];

		$this->assertSame( '', Settings::sanitize_azure_openai_endpoint( '' ) );
		$this->assertSame( 'azure-key', Settings::sanitize_azure_openai_key( 'azure-key' ) );
		$this->assertSame(
			'embed-deployment',
			Settings::sanitize_azure_embedding_deployment( 'embed-deployment' )
		);
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_openai_native_settings_skip_remote_validation_when_credentials_are_unchanged(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
		];

		$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( 'native-key', Settings::sanitize_openai_native_api_key( 'native-key' ) );
		$this->assertSame(
			'text-embedding-3-large',
			Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' )
		);
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

	public function test_sanitize_azure_settings_validate_complete_values_when_connector_provider_is_selected(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME                     => 'anthropic',
			'flavor_agent_azure_openai_endpoint'      => 'https://old.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'old-key',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
		];
		WordPressTestState::$connectors                 = [
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
		$_POST = [
			'option_page'                             => 'flavor_agent_settings',
			Provider::OPTION_NAME                     => 'anthropic',
			'flavor_agent_azure_openai_endpoint'      => 'https://new.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'new-key',
			'flavor_agent_azure_embedding_deployment' => 'new-embed',
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
		];

		$this->assertSame( 'anthropic', Settings::sanitize_openai_provider( 'anthropic' ) );
		$this->assertSame(
			'https://new.openai.azure.com/',
			Settings::sanitize_azure_openai_endpoint( 'https://new.openai.azure.com/' )
		);
		$this->assertSame( 'new-key', Settings::sanitize_azure_openai_key( 'new-key' ) );
		$this->assertSame( 'new-embed', Settings::sanitize_azure_embedding_deployment( 'new-embed' ) );
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_openai_native_settings_accept_verified_values_and_validate_once_per_save(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'azure_openai',
			'flavor_agent_openai_native_api_key'         => 'old-key',
			'flavor_agent_openai_native_embedding_model' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'new-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
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
		];

		$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( 'new-key', Settings::sanitize_openai_native_api_key( 'new-key' ) );
		$this->assertSame(
			'text-embedding-3-large',
			Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' )
		);
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame( 'https://api.openai.com/v1/embeddings', WordPressTestState::$remote_post_calls[0]['url'] );
		$this->assertSame(
			'Bearer new-key',
			WordPressTestState::$remote_post_calls[0]['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_sanitize_openai_native_settings_revert_invalid_values_and_report_fallback_notices(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'old-key',
			'flavor_agent_openai_native_embedding_model' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'bad-key',
			'flavor_agent_openai_native_embedding_model' => 'bad-embed',
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
		$this->assertSame(
			[
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_openai_native_validation',
					'message' => 'OpenAI Native validation failed. Check the API key and embedding model, then try again.',
					'type'    => 'error',
				],
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_openai_native_validation_preserved',
					'message' => 'We kept your previous OpenAI Native settings because validation failed.',
					'type'    => 'warning',
				],
			],
			WordPressTestState::$settings_errors
		);
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_openai_native_settings_validate_with_connector_key_when_plugin_key_is_blank(): void {
		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'azure_openai',
			'connectors_ai_openai_api_key'               => 'connector-key',
			'flavor_agent_openai_native_api_key'         => '',
			'flavor_agent_openai_native_embedding_model' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                                => 'flavor_agent_settings',
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => '',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
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
		];

		$this->assertSame( 'openai_native', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( '', Settings::sanitize_openai_native_api_key( '' ) );
		$this->assertSame(
			'text-embedding-3-large',
			Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' )
		);
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'Bearer connector-key',
			WordPressTestState::$remote_post_calls[0]['args']['headers']['Authorization'] ?? null
		);
	}

	public function test_sanitize_openai_native_settings_validate_complete_values_when_connector_provider_is_selected(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME                        => 'anthropic',
			'flavor_agent_openai_native_api_key'         => 'old-key',
			'flavor_agent_openai_native_embedding_model' => 'old-embed',
		];
		WordPressTestState::$connectors                 = [
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
		$_POST = [
			'option_page'                                => 'flavor_agent_settings',
			Provider::OPTION_NAME                        => 'anthropic',
			'flavor_agent_openai_native_api_key'         => 'new-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
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
		];

		$this->assertSame( 'anthropic', Settings::sanitize_openai_provider( 'anthropic' ) );
		$this->assertSame( 'new-key', Settings::sanitize_openai_native_api_key( 'new-key' ) );
		$this->assertSame(
			'text-embedding-3-large',
			Settings::sanitize_openai_native_embedding_model( 'text-embedding-3-large' )
		);
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
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

	public function test_sanitize_qdrant_settings_revert_invalid_values_and_report_fallback_notices(): void {
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
		$this->assertSame(
			[
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_qdrant_validation',
					'message' => 'Qdrant validation failed. Check the cluster URL and API key, then try again.',
					'type'    => 'error',
				],
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_qdrant_validation_preserved',
					'message' => 'We kept your previous Qdrant settings because validation failed.',
					'type'    => 'warning',
				],
			],
			WordPressTestState::$settings_errors
		);
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

	public function test_sanitize_cloudflare_settings_reverts_invalid_values_and_reports_fallback_notices_from_error_responses(): void {
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
		$this->assertSame(
			[
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_cloudflare_ai_search_validation',
					'message' => 'Docs grounding validation failed. Check the Cloudflare account, instance, and API token, then try again.',
					'type'    => 'error',
				],
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_cloudflare_ai_search_validation_preserved',
					'message' => 'We kept your previous docs grounding settings because validation failed.',
					'type'    => 'warning',
				],
			],
			WordPressTestState::$settings_errors
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

	public function test_sanitize_cloudflare_settings_reverts_incompatible_values_and_reports_fallback_notices(): void {
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
		$this->assertSame(
			[
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_cloudflare_ai_search_validation',
					'message' => 'Cloudflare AI Search validation could not confirm trusted developer.wordpress.org content from this instance. Use the official WordPress developer docs index before saving these credentials.',
					'type'    => 'error',
				],
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_cloudflare_ai_search_validation_preserved',
					'message' => 'We kept your previous docs grounding settings because validation failed.',
					'type'    => 'warning',
				],
			],
			WordPressTestState::$settings_errors
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
		Page::render_settings_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Settings saved.', $output );
		$this->assertStringContainsString( 'Authentication error', $output );
		$this->assertArrayNotHasKey( 'settings_errors', WordPressTestState::$transients );
	}

	public function test_render_settings_notices_displays_plugin_messages_when_request_scoped_feedback_is_present(): void {
		$_GET = [
			'settings-updated'                   => 'true',
			'flavor_agent_settings_feedback_key' => 'token-user-1',
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
		Page::render_settings_notices();
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

	public function test_render_page_keeps_direct_provider_controls_available_for_connector_provider(): void {
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
		];

		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Anthropic is connector-backed', $output );
		$this->assertStringContainsString( 'name="flavor_agent_azure_embedding_deployment"', $output );
		$this->assertStringContainsString( 'name="flavor_agent_openai_native_embedding_model"', $output );
		$this->assertStringContainsString( 'Legacy Direct Azure Settings', $output );
		$this->assertStringContainsString( 'OpenAI Embeddings', $output );
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

	public function test_render_text_field_redacts_saved_password_values(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_key' => 'saved-secret-key',
		];

		ob_start();
		Settings::render_text_field(
			[
				'option'      => 'flavor_agent_azure_openai_key',
				'label_for'   => 'flavor_agent_azure_openai_key',
				'type'        => 'password',
				'description' => 'Azure API key used for embeddings.',
			]
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $output );
		$this->assertStringContainsString( 'value=""', $output );
		$this->assertStringContainsString( 'data-saved-secret="true"', $output );
		$this->assertStringContainsString( 'Saved', $output );
		$this->assertStringNotContainsString( 'saved-secret-key', $output );
		$this->assertStringContainsString( 'this field is intentionally blank', $output );
	}

	public function test_register_settings_exposes_block_structural_actions_toggle(): void {
		Settings::register_settings();

		$this->assertArrayHasKey(
			'flavor_agent_block_structural_actions_enabled',
			$GLOBALS['wp_registered_settings']
		);
		$this->assertSame(
			'boolean',
			$GLOBALS['wp_registered_settings']['flavor_agent_block_structural_actions_enabled']['type']
		);
		$this->assertSame(
			[ Settings::class, 'sanitize_block_structural_actions_enabled' ],
			$GLOBALS['wp_registered_settings']['flavor_agent_block_structural_actions_enabled']['sanitize_callback']
		);
		$this->assertArrayHasKey(
			'flavor_agent_block_structural_actions_enabled',
			$GLOBALS['wp_settings_fields'][ Config::PAGE_SLUG ]['flavor_agent_experimental_features']
		);
	}

	public function test_render_page_includes_experimental_structural_actions_toggle(): void {
		WordPressTestState::$options = [
			'flavor_agent_block_structural_actions_enabled' => true,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '5. Experimental Features', $output );
		$this->assertStringContainsString( 'Block Structural Actions', $output );
		$this->assertStringContainsString(
			'name="flavor_agent_block_structural_actions_enabled"',
			$output
		);
		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'checked="checked"', $output );
		$this->assertStringContainsString(
			'Enables review-first selected-block pattern insert and replace actions.',
			$output
		);
	}

	public function test_sanitize_block_structural_actions_does_not_mark_default_false_as_changed(): void {
		$_POST = [
			'option_page'                        => Config::OPTION_GROUP,
			'flavor_agent_settings_feedback_key' => 'experiments-default',
		];

		$this->assertFalse( Settings::sanitize_block_structural_actions_enabled( '0' ) );
		$this->assertSame( [], WordPressTestState::$transients );

		$this->assertTrue( Settings::sanitize_block_structural_actions_enabled( '1' ) );
		$feedback = array_values( WordPressTestState::$transients )[0] ?? [];

		$this->assertTrue( (bool) ( $feedback['changed_sections']['experiments'] ?? false ) );
	}

	public function test_register_contextual_help_uses_native_wp_screen_help_tabs(): void {
		$screen                             = new \WP_Screen();
		WordPressTestState::$current_screen = $screen;

		Settings::register_contextual_help();

		$this->assertCount( 3, $screen->help_tabs );
		$this->assertSame( 'flavor-agent-overview', $screen->help_tabs[0]['id'] );
		$this->assertSame( 'Overview', $screen->help_tabs[0]['title'] );
		$this->assertStringContainsString( 'This screen keeps inline copy short', $screen->help_tabs[0]['content'] );
		$this->assertStringContainsString( 'Configure chat first. Settings &gt; Connectors is the primary path', $screen->help_tabs[0]['content'] );
		$this->assertSame( 'flavor-agent-configuration', $screen->help_tabs[1]['id'] );
		$this->assertStringContainsString( 'Settings &gt; Connectors', $screen->help_tabs[1]['content'] );
		$this->assertStringContainsString( 'built-in public developer.wordpress.org endpoint by default', $screen->help_tabs[1]['content'] );
		$this->assertStringContainsString( 'Cloudflare override fields are only for older installs or explicit custom-endpoint use.', $screen->help_tabs[1]['content'] );
		$this->assertSame( 'flavor-agent-troubleshooting', $screen->help_tabs[2]['id'] );
		$this->assertStringContainsString( 'Guidelines import fills the legacy form first', $screen->help_tabs[2]['content'] );
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
		$this->assertStringContainsString( 'Chat is handled by Settings &gt; Connectors; this screen configures embeddings and supporting services.', $output );
		$this->assertStringContainsString( 'Optional', $output );
		$this->assertStringContainsString( 'Add vector search for pattern recommendations.', $output );
		$this->assertStringContainsString( 'Ground responses with developer.wordpress.org docs.', $output );
		$this->assertStringContainsString( 'Store plugin-owned site, writing, image, and block guidance.', $output );
		$this->assertStringContainsString( 'Override values are live-probed before saving', $output );
		$this->assertStringContainsString( 'The instance must return trusted developer.wordpress.org chunks.', $output );
		$this->assertStringContainsString( 'Needs Account &gt; AI Search:Edit and Account &gt; AI Search:Run permissions.', esc_html( $output ) );
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

	public function test_pattern_setup_copy_points_to_embeddings_not_chat_provider(): void {
		$state                      = $this->build_default_open_group_state();
		$state['qdrant_configured'] = true;

		$status_blocks = State::get_section_status_blocks( Config::GROUP_PATTERNS, $state, [] );

		$this->assertSame(
			'Qdrant is configured, but pattern recommendations still need a configured embeddings backend.',
			$status_blocks[0]['message'] ?? ''
		);

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Choose and complete an embeddings backend, then add Qdrant before the Sync Pattern Catalog button can run.',
			$output
		);
		$this->assertStringNotContainsString( 'Chat Provider', $output );
	}

	public function test_should_enqueue_admin_assets_accepts_settings_and_admin_hook_variants(): void {
		$this->assertTrue(
			Assets::should_enqueue_assets( 'settings_page_flavor-agent', 'admin_page_flavor-agent' )
		);
		$this->assertTrue(
			Assets::should_enqueue_assets( 'admin_page_flavor-agent', 'settings_page_flavor-agent' )
		);
	}

	public function test_should_enqueue_admin_assets_falls_back_to_request_slug_and_current_screen(): void {
		$_GET = [
			'page' => 'flavor-agent',
		];

		$this->assertTrue(
			Assets::should_enqueue_assets( 'dashboard_page_demo', 'settings_page_flavor-agent' )
		);

		$_GET                               = [];
		WordPressTestState::$current_screen = (object) [
			'id'   => 'settings_page_flavor-agent',
			'base' => 'settings_page_flavor-agent',
		];

		$this->assertTrue(
			Assets::should_enqueue_assets( 'dashboard_page_demo', 'admin_page_flavor-agent' )
		);
	}

	public function test_should_enqueue_admin_assets_rejects_other_admin_pages(): void {
		$_GET                               = [
			'page' => 'plugins',
		];
		WordPressTestState::$current_screen = (object) [
			'id'   => 'plugins',
			'base' => 'plugins',
		];

		$this->assertFalse(
			Assets::should_enqueue_assets( 'plugins.php', 'settings_page_flavor-agent' )
		);
	}

	public function test_has_settings_updated_query_flag_requires_true_literal(): void {
		$_GET = [
			'settings-updated' => 'true',
		];
		$this->assertTrue( Feedback::has_settings_updated_query_flag() );

		$_GET = [
			'settings-updated' => '1',
		];
		$this->assertFalse( Feedback::has_settings_updated_query_flag() );

		$_GET = [
			'settings-updated' => 'false',
		];
		$this->assertFalse( Feedback::has_settings_updated_query_flag() );
	}

	public function test_merge_html_attributes_appends_class_values(): void {
		$result = Utils::merge_html_attributes(
			[
				'class' => 'base-class',
				'href'  => 'https://example.com',
			],
			[
				'class'          => 'extra-class',
				'data-test-role' => 'badge',
			]
		);

		$this->assertSame( 'base-class extra-class', $result['class'] );
		$this->assertSame( 'https://example.com', $result['href'] );
		$this->assertSame( 'badge', $result['data-test-role'] );
	}

	public function test_encode_json_payload_returns_fallback_for_unencodable_values(): void {
		$handle = fopen( 'php://temp', 'r' );

		$this->assertIsResource( $handle );

		try {
			$this->assertSame( '[]', Utils::encode_json_payload( $handle ) );
		} finally {
			fclose( $handle );
		}
	}

	public function test_encode_json_payload_hex_escapes_script_terminators_when_requested(): void {
		$result = Utils::encode_json_payload(
			[
				'label' => '</script><script>alert(1)</script>',
			],
			'[]',
			JSON_HEX_TAG
		);

		$this->assertStringContainsString( '\\u003C/script\\u003E', $result );
		$this->assertStringNotContainsString( '</script>', $result );
	}

	public function test_read_admin_asset_metadata_rejects_malformed_manifest(): void {
		$asset_path = tempnam( sys_get_temp_dir(), 'fa-asset-' );

		$this->assertIsString( $asset_path );
		file_put_contents( $asset_path, "<?php return [ 'version' => '1.0.0' ];" );

		try {
			$this->assertNull( Assets::read_asset_metadata( $asset_path ) );
		} finally {
			unlink( $asset_path );
		}
	}

	public function test_read_admin_asset_metadata_rejects_invalid_version_types(): void {
		$asset_path = tempnam( sys_get_temp_dir(), 'fa-asset-' );

		$this->assertIsString( $asset_path );
		file_put_contents(
			$asset_path,
			"<?php return [ 'dependencies' => [ 'wp-element' ], 'version' => [ '1.0.0' ] ];"
		);

		try {
			$this->assertNull( Assets::read_asset_metadata( $asset_path ) );
		} finally {
			unlink( $asset_path );
		}
	}

	public function test_read_admin_asset_metadata_accepts_valid_manifest(): void {
		$asset_path = tempnam( sys_get_temp_dir(), 'fa-asset-' );

		$this->assertIsString( $asset_path );
		file_put_contents(
			$asset_path,
			"<?php return [ 'dependencies' => [ 'wp-element', '', 123 ], 'version' => '1.2.3' ];"
		);

		try {
			$this->assertSame(
				[
					'dependencies' => [ 'wp-element' ],
					'version'      => '1.2.3',
				],
				Assets::read_asset_metadata( $asset_path )
			);
		} finally {
			unlink( $asset_path );
		}
	}

	public function test_read_settings_page_feedback_only_consumes_when_requested(): void {
		WordPressTestState::$current_user_id = 7;
		$storage_key                         = Feedback::get_storage_key( 'feedback-token' );

		WordPressTestState::$transients[ $storage_key ] = [
			'changed_sections' => [
				'chat' => true,
			],
			'messages'         => [
				'chat' => [
					[
						'tone'    => 'success',
						'message' => 'Chat provider saved.',
					],
				],
			],
			'focus_section'    => 'chat',
		];

		$peeked = Feedback::read_settings_page_feedback( 'feedback-token', false );
		$this->assertTrue( (bool) $peeked['changed_sections']['chat'] );
		$this->assertSame( 'Chat provider saved.', $peeked['messages']['chat'][0]['message'] );
		$this->assertArrayHasKey( $storage_key, WordPressTestState::$transients );

		$consumed = Feedback::read_settings_page_feedback( 'feedback-token', true );
		$this->assertSame( 'chat', $consumed['focus_section'] );
		$this->assertArrayNotHasKey( $storage_key, WordPressTestState::$transients );
	}

	public function test_get_feedback_message_entries_accepts_legacy_single_message_shape(): void {
		$entries = Feedback::get_feedback_message_entries(
			[
				'messages' => [
					'chat' => [
						'tone'    => 'warning',
						'message' => 'Legacy warning.',
					],
				],
			],
			'chat'
		);

		$this->assertSame(
			[
				[
					'tone'    => 'warning',
					'message' => 'Legacy warning.',
				],
			],
			$entries
		);
	}

	public function test_record_section_feedback_message_appends_messages_for_the_same_section(): void {
		$_POST = [
			'option_page'                        => 'flavor_agent_settings',
			'flavor_agent_settings_feedback_key' => 'feedback-token',
		];

		Feedback::record_section_feedback_message(
			'chat',
			'warning',
			'First message.'
		);
		Feedback::record_section_feedback_message(
			'chat',
			'error',
			'Second message.',
			true
		);

		$feedback = array_values( WordPressTestState::$transients )[0] ?? [];

		$this->assertSame(
			[
				[
					'tone'    => 'warning',
					'message' => 'First message.',
				],
				[
					'tone'    => 'error',
					'message' => 'Second message.',
				],
			],
			$feedback['messages']['chat'] ?? []
		);
		$this->assertSame( 'chat', $feedback['focus_section'] ?? '' );
	}

	public function test_record_section_feedback_message_preserves_the_first_focus_section(): void {
		$_POST = [
			'option_page'                        => 'flavor_agent_settings',
			'flavor_agent_settings_feedback_key' => 'focus-token',
		];

		Feedback::record_section_feedback_message(
			'chat',
			'error',
			'Chat failed.',
			true
		);
		Feedback::record_section_feedback_message(
			'docs',
			'error',
			'Docs failed.',
			true
		);

		$feedback = array_values( WordPressTestState::$transients )[0] ?? [];

		$this->assertSame( 'chat', $feedback['focus_section'] ?? '' );
	}

	public function test_render_settings_save_summary_keeps_other_success_notices_when_one_section_has_errors(): void {
		$_GET = [
			'settings-updated' => 'true',
		];

		ob_start();
		Page::render_settings_save_summary(
			[
				'changed_sections' => [
					'chat' => true,
					'docs' => true,
				],
				'messages'         => [
					'chat' => [
						[
							'tone'    => 'error',
							'message' => 'Azure validation failed.',
						],
					],
				],
			]
		);
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Chat provider saved.', $output );
		$this->assertStringContainsString( 'Docs grounding settings saved.', $output );
	}

	public function test_determine_default_open_group_defaults_to_chat_when_runtime_chat_is_not_ready(): void {
		$state                           = $this->build_default_open_group_state();
		$state['runtime_chat']           = [ 'configured' => false ];
		$state['runtime_embedding']      = [ 'configured' => true ];
		$state['qdrant_configured']      = true;
		$state['docs_configured']        = true;
		$state['prewarm_state']          = [ 'status' => 'failed' ];
		$state['runtime_docs_grounding'] = [ 'status' => 'error' ];

		$this->assertSame(
			'chat',
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_patterns_when_pattern_backends_are_partial(): void {
		$state                      = $this->build_default_open_group_state();
		$state['qdrant_configured'] = true;

		$this->assertSame(
			'patterns',
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_docs_for_runtime_failures(): void {
		$state                           = $this->build_default_open_group_state();
		$state['docs_configured']        = true;
		$state['runtime_docs_grounding'] = [ 'status' => 'retrying' ];

		$this->assertSame(
			'docs',
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_falls_back_to_chat_when_everything_is_healthy(): void {
		$this->assertSame(
			'chat',
			State::determine_default_open_group( $this->build_default_open_group_state() )
		);
	}

	public function test_resolve_azure_submission_values_invalidates_the_cache_when_the_request_payload_changes(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => '',
			'flavor_agent_azure_openai_key'           => '',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => '',
			'flavor_agent_azure_embedding_deployment' => 'first-embed',
		];

		$first_resolution = Validation::resolve_azure_submission_values();

		$_POST['flavor_agent_azure_embedding_deployment'] = 'second-embed';

		$second_resolution = Validation::resolve_azure_submission_values();

		$this->assertSame( 'first-embed', $first_resolution['flavor_agent_azure_embedding_deployment'] );
		$this->assertSame( 'second-embed', $second_resolution['flavor_agent_azure_embedding_deployment'] );
	}

	public function test_submission_request_fingerprint_does_not_retain_raw_post_secrets(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint'      => '',
			'flavor_agent_azure_openai_key'           => '',
			'flavor_agent_azure_embedding_deployment' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                             => 'flavor_agent_settings',
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'posted-secret-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];

		Validation::resolve_azure_submission_values();

		$reflection = new \ReflectionClass( Validation::class );
		$property   = $reflection->getProperty( 'submission_request_fingerprint' );
		$property->setAccessible( true );
		$state = $property->getValue();

		$this->assertIsArray( $state );
		$this->assertArrayNotHasKey( 'raw_post', $state );
		$this->assertStringNotContainsString( 'posted-secret-key', (string) wp_json_encode( $state ) );
	}

	public function test_render_page_keeps_cloudflare_override_controls_available(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
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
			'Override values are live-probed before saving and are rejected unless the instance returns trusted developer.wordpress.org guidance.',
			$output
		);
		$this->assertStringContainsString(
			'Saved override values are present. Clear all three fields to stop using the override.',
			$output
		);
		$this->assertStringContainsString(
			'Optional override. Cloudflare account ID for the AI Search instance that indexes developer.wordpress.org docs.',
			$output
		);
		$this->assertStringContainsString(
			'Optional override. Enter the AI Search instance name from the REST path. For namespace-scoped Search, use namespace/instance-name. The instance must return trusted developer.wordpress.org chunks.',
			$output
		);
		$this->assertStringContainsString(
			'Optional override. Needs Account &gt; AI Search:Edit and Account &gt; AI Search:Run permissions.',
			esc_html( $output )
		);
		$this->assertStringContainsString(
			'Saved value exists. For security, this field is intentionally blank.',
			$output
		);
		$this->assertStringNotContainsString( 'Legacy override only.', $output );
		$this->assertStringNotContainsString( 'Required for docs grounding.', $output );
	}

	public function test_render_page_consumes_request_scoped_feedback_only_for_the_matching_user(): void {
		WordPressTestState::$current_user_id = 1;
		$_POST                               = [
			'option_page'                        => 'flavor_agent_settings',
			'flavor_agent_settings_feedback_key' => 'token-user-1',
		];

		Settings::sanitize_openai_provider( 'openai_native' );

		$_POST = [];
		$_GET  = [
			'settings-updated'                   => 'true',
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

	public function test_render_page_marks_guidelines_controls_as_migration_tooling_when_core_storage_exists(): void {
		WordPressTestState::$registered_post_types['wp_guideline'] = [
			'show_in_rest' => true,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Core Guidelines storage detected.', $output );
		$this->assertStringContainsString( 'legacy migration tooling', $output );
		$this->assertStringContainsString( 'name="flavor_agent_guideline_site"', $output );
		$this->assertStringContainsString( 'Import JSON', $output );
		$this->assertStringContainsString( 'Export JSON', $output );
	}

	public function test_render_page_outputs_parseable_guidelines_block_options_json(): void {
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertSame(
			1,
			preg_match(
				'/<script type="application\/json" data-guidelines-block-options>(.*?)<\/script>/s',
				$output,
				$matches
			)
		);
		$this->assertIsArray( json_decode( $matches[1], true ) );
	}

	public function test_sanitize_guideline_copy_marks_guidelines_feedback_and_sanitizes_text(): void {
		$_POST = [
			'option_page'                        => 'flavor_agent_settings',
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
			'option_page'                        => 'flavor_agent_settings',
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
		$this->assertSame(
			'Pattern index collection is missing and needs a rebuild.',
			State::get_pattern_sync_reason_label( 'collection_missing' )
		);
		$this->assertSame(
			'Pattern index collection vector size no longer matches the active embedding configuration.',
			State::get_pattern_sync_reason_label( 'collection_size_mismatch' )
		);
	}

	public function test_get_docs_overview_status_reports_retrying_from_runtime_grounding_state(): void {
		$result = State::get_docs_overview_status(
			[
				'docs_configured'        => true,
				'prewarm_state'          => [
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
			'flavor_agent_docs_warm_queue'                 => [
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

	/**
	 * @return array<string, mixed>
	 */
	private function build_default_open_group_state(): array {
		return [
			'runtime_chat'           => [ 'configured' => true ],
			'pattern_state'          => [
				'last_error' => '',
				'status'     => 'ok',
			],
			'patterns_ready'         => false,
			'qdrant_configured'      => false,
			'runtime_embedding'      => [ 'configured' => false ],
			'docs_configured'        => false,
			'prewarm_state'          => [ 'status' => 'ok' ],
			'runtime_docs_grounding' => [ 'status' => 'ok' ],
		];
	}

	private function reset_validation_state(): void {
		Validation::reset();
		Assets::reset();
	}
}
