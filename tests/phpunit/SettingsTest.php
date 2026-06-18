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
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Settings;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

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

	public function test_unposted_workers_ai_values_are_preserved_when_legacy_provider_payload_is_submitted(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'openai_native',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'workers-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/baai/bge-large-en-v1.5',
		];
		$_POST                       = [
			'option_page'         => Config::OPTION_GROUP,
			Provider::OPTION_NAME => 'openai_native',
		];

		$this->assertSame( 'cloudflare_workers_ai', Settings::sanitize_openai_provider( 'openai_native' ) );
		$this->assertSame( 'workers-account', Settings::sanitize_cloudflare_workers_ai_account_id( null ) );
		$this->assertSame( 'workers-token', Settings::sanitize_cloudflare_workers_ai_api_token( null ) );
		$this->assertSame( '@cf/baai/bge-large-en-v1.5', Settings::sanitize_cloudflare_workers_ai_embedding_model( null ) );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_unposted_blank_workers_ai_embedding_model_uses_default(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'openai_native',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'workers-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '',
		];
		$_POST                       = [
			'option_page'         => Config::OPTION_GROUP,
			Provider::OPTION_NAME => 'openai_native',
		];

		$this->assertSame(
			WorkersAIEmbeddingConfiguration::DEFAULT_MODEL,
			Settings::sanitize_cloudflare_workers_ai_embedding_model( null )
		);
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_unposted_reasoning_effort_migrates_saved_legacy_azure_option(): void {
		WordPressTestState::$options = [
			Config::OPTION_LEGACY_AZURE_REASONING_EFFORT => 'xhigh',
		];
		$_POST                       = [
			'option_page' => Config::OPTION_GROUP,
		];

		$this->assertSame( 'xhigh', Settings::sanitize_reasoning_effort( null ) );
	}

	public function test_sanitize_openai_provider_forces_cloudflare_workers_ai(): void {
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

		$this->assertSame( 'cloudflare_workers_ai', Settings::sanitize_openai_provider( 'anthropic' ) );
		$this->assertSame( 'cloudflare_workers_ai', Settings::sanitize_openai_provider( 'openai_native' ) );
	}

	public function test_sanitize_workers_ai_settings_accept_verified_values_and_validate_once_per_save(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'openai_native',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-old',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-old',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => 'old-model',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

		WordPressTestState::$remote_post_responses = [
			[
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'data' => [
							[
								'embedding' => [ 0.1, 0.2, 0.3 ],
							],
						],
					]
				),
			],
		];

		$this->assertSame( 'cloudflare_workers_ai', Settings::sanitize_openai_provider( 'cloudflare_workers_ai' ) );
		$this->assertSame( 'account-123', Settings::sanitize_cloudflare_workers_ai_account_id( 'account-123' ) );
		$this->assertSame( 'token-xyz', Settings::sanitize_cloudflare_workers_ai_api_token( 'token-xyz' ) );
		$this->assertSame(
			'@cf/qwen/qwen3-embedding-0.6b',
			Settings::sanitize_cloudflare_workers_ai_embedding_model( '@cf/qwen/qwen3-embedding-0.6b' )
		);
		$this->assertSame( [], WordPressTestState::$settings_errors );
		$this->assertCount( 1, WordPressTestState::$remote_post_calls );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
			WordPressTestState::$remote_post_calls[0]['url']
		);
	}

	public function test_workers_ai_submission_validation_no_longer_checks_submitted_provider_choice(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'anthropic',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-old',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];
		$_POST                       = [
			'option_page'                                  => Config::OPTION_GROUP,
			'_wpnonce'                                     => wp_create_nonce( Config::OPTION_GROUP . '-options' ),
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-new',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'data' => [
						[ 'embedding' => [ 0.1, 0.2, 0.3 ] ],
					],
				]
			),
		];

		$result = Settings::sanitize_cloudflare_workers_ai_api_token( 'token-new' );

		$this->assertSame( 'token-new', $result );
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/accounts/account-123/ai/v1/embeddings',
			WordPressTestState::$last_remote_post['url']
		);
	}

	public function test_sanitize_workers_ai_settings_warns_when_validated_dimension_differs_from_saved_qdrant_index(): void {
		WordPressTestState::$options = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_QDRANT,
			PatternIndex::STATE_OPTION                     => [
				'status'              => 'ready',
				'last_synced_at'      => '2026-05-05T00:00:00+00:00',
				'embedding_dimension' => 1024,
			],
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-old',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-old',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => 'old-model',
		];
		$_POST                       = [
			'option_page'                                  => Config::OPTION_GROUP,
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/future/embedding-model',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 200 ],
			'body'     => wp_json_encode(
				[
					'data' => [
						[
							'embedding' => [ 0.1, 0.2, 0.3 ],
						],
					],
				]
			),
		];

		$this->assertSame( 'account-123', Settings::sanitize_cloudflare_workers_ai_account_id( 'account-123' ) );

		$this->assertSame(
			[
				[
					'setting' => Config::OPTION_GROUP,
					'code'    => 'flavor_agent_workers_ai_dimension_changed',
					'message' => 'The saved Qdrant pattern index uses 1024 embedding dimensions, but the validated Cloudflare Workers AI model returns 3. Re-sync patterns before relying on Qdrant pattern recommendations.',
					'type'    => 'warning',
				],
			],
			WordPressTestState::$settings_errors
		);
	}

	public function test_sanitize_workers_ai_settings_reverts_invalid_values_and_reports_fallback_notices(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-old',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-old',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => 'old-model',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-new',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-new',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
		];

		WordPressTestState::$remote_post_response = [
			'response' => [ 'code' => 403 ],
			'body'     => wp_json_encode(
				[
					'error' => [
						'message' => 'Workers AI authentication failed',
					],
				]
			),
		];

		$this->assertSame( 'cloudflare_workers_ai', Settings::sanitize_openai_provider( 'cloudflare_workers_ai' ) );
		$this->assertSame( 'account-old', Settings::sanitize_cloudflare_workers_ai_account_id( 'account-new' ) );
		$this->assertSame( 'token-old', Settings::sanitize_cloudflare_workers_ai_api_token( 'token-new' ) );
		$this->assertSame( 'old-model', Settings::sanitize_cloudflare_workers_ai_embedding_model( '@cf/qwen/qwen3-embedding-0.6b' ) );
		$this->assertSame(
			[
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_cloudflare_workers_ai_validation',
					'message' => 'Cloudflare Workers AI validation failed. Check the account ID, API token, and embedding model, then try again.',
					'type'    => 'error',
				],
				[
					'setting' => 'flavor_agent_settings',
					'code'    => 'flavor_agent_cloudflare_workers_ai_validation_preserved',
					'message' => 'We kept your previous Cloudflare Workers AI settings because validation failed.',
					'type'    => 'warning',
				],
			],
			WordPressTestState::$settings_errors
		);
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
				'code'    => 'flavor_agent_qdrant_validation',
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
				'code'    => 'flavor_agent_qdrant_validation',
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

	public function test_render_select_field_lists_only_workers_ai_for_embedding_provider(): void {
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

		$this->assertStringContainsString( 'Cloudflare Workers AI', $output );
		$this->assertStringContainsString( 'value="cloudflare_workers_ai"', $output );
		$this->assertStringNotContainsString( 'Anthropic (Settings &gt; Connectors)', $output );
		$this->assertStringNotContainsString( 'value="anthropic" selected=', $output );
		$this->assertStringNotContainsString( 'Google (Settings &gt; Connectors)', $output );
	}

	public function test_render_page_ignores_saved_legacy_connector_pin_and_shows_cloudflare_embeddings(): void {
		WordPressTestState::$options    = [
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

		$this->assertStringNotContainsString( 'Legacy connector pin', $output );
		$this->assertStringNotContainsString( 'value="anthropic" selected=', $output );
		$this->assertStringContainsString( 'name="flavor_agent_openai_provider" value="cloudflare_workers_ai"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_azure_embedding_deployment"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_openai_native_embedding_model"', $output );
		$this->assertStringContainsString( 'name="flavor_agent_cloudflare_workers_ai_embedding_model"', $output );
	}

	public function test_render_page_ignores_unregistered_legacy_connector_pin(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors                 = [];
		WordPressTestState::$ai_client_provider_support = [];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Legacy connector pin', $output );
		$this->assertStringNotContainsString( 'value="anthropic" selected=', $output );
		$this->assertStringNotContainsString( 'saved legacy connector is not currently registered', $output );
		$this->assertStringContainsString( 'name="flavor_agent_openai_provider" value="cloudflare_workers_ai"', $output );
		$this->assertStringContainsString( 'name="flavor_agent_cloudflare_workers_ai_embedding_model"', $output );
	}

	public function test_render_page_ignores_saved_azure_embedding_provider(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                     => 'azure_openai',
			'flavor_agent_azure_openai_endpoint'      => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'           => 'azure-key',
			'flavor_agent_azure_embedding_deployment' => 'embed-deployment',
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Removed provider: Azure OpenAI', $output );
		$this->assertStringNotContainsString( 'Azure OpenAI embedding settings are no longer editable in Flavor Agent.', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_azure_openai_endpoint"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_azure_openai_key"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_azure_embedding_deployment"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_openai_native_embedding_model"', $output );
		$this->assertStringContainsString( 'name="flavor_agent_openai_provider" value="cloudflare_workers_ai"', $output );
		$this->assertStringContainsString( 'name="flavor_agent_cloudflare_workers_ai_embedding_model"', $output );
	}

	public function test_render_page_includes_workers_ai_embedding_controls_when_selected(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME => 'cloudflare_workers_ai',
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cloudflare Workers AI', $output );
		$this->assertStringContainsString( 'Used for embeddings.', $output );
		$this->assertStringContainsString(
			'name="flavor_agent_cloudflare_workers_ai_account_id"',
			$output
		);
		$this->assertStringContainsString(
			'name="flavor_agent_cloudflare_workers_ai_api_token"',
			$output
		);
		$this->assertStringContainsString(
			'name="flavor_agent_cloudflare_workers_ai_embedding_model"',
			$output
		);
	}

	public function test_render_page_exposes_runtime_attention_section_for_settings_controller(): void {
		WordPressTestState::$ai_client_supported = true;
		WordPressTestState::$options             = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'workers-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_qdrant_url'                      => 'https://qdrant.example',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			PatternIndex::STATE_OPTION                     => [
				'status'         => 'stale',
				'last_error'     => null,
				'last_synced_at' => '2026-05-18T10:00:00Z',
				'stale_reason'   => 'pattern_registry_changed',
			],
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-default-section="patterns"', $output );
		$this->assertStringContainsString( 'data-attention-section="patterns"', $output );
	}

	public function test_register_settings_exposes_pattern_retrieval_backend_and_private_ai_search_options(): void {
		Settings::register_settings();

		$this->assertArrayHasKey(
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND,
			$GLOBALS['wp_registered_settings']
		);
		$this->assertSame(
			'string',
			$GLOBALS['wp_registered_settings'][ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ]['type']
		);
		$this->assertSame(
			[ Settings::class, 'sanitize_pattern_retrieval_backend' ],
			$GLOBALS['wp_registered_settings'][ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ]['sanitize_callback']
		);
		$this->assertSame(
			Config::PATTERN_BACKEND_QDRANT,
			$GLOBALS['wp_registered_settings'][ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ]['default']
		);
		$this->assertSame(
			[
				Config::PATTERN_BACKEND_QDRANT => 'Qdrant vector storage',
				Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH => 'Cloudflare AI Search managed index',
			],
			$GLOBALS['wp_settings_fields'][ Config::PAGE_SLUG ]['flavor_agent_pattern_retrieval'][ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ]['args']['choices']
		);

		foreach (
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
				Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
			] as $option_name
		) {
			$this->assertArrayHasKey( $option_name, $GLOBALS['wp_registered_settings'] );
		}

		$this->assertArrayNotHasKey( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID, $GLOBALS['wp_registered_settings'] );
		$this->assertArrayNotHasKey( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE, $GLOBALS['wp_registered_settings'] );
		$this->assertArrayNotHasKey( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN, $GLOBALS['wp_registered_settings'] );
		$this->assertSame(
			[ Settings::class, 'sanitize_cloudflare_pattern_ai_search_instance_id' ],
			$GLOBALS['wp_registered_settings'][ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ]['sanitize_callback']
		);
		$this->assertSame(
			'number',
			$GLOBALS['wp_registered_settings'][ Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH ]['type']
		);
		$this->assertSame(
			Config::PATTERN_AI_SEARCH_THRESHOLD_DEFAULT,
			$GLOBALS['wp_registered_settings'][ Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH ]['default']
		);
	}

	public function test_render_page_includes_private_pattern_ai_search_settings_contract(): void {
		WordPressTestState::$options = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Pattern Storage', $output );
		$this->assertStringContainsString(
			'name="' . Config::OPTION_PATTERN_RETRIEVAL_BACKEND . '"',
			$output
		);
		$this->assertStringContainsString(
			'value="' . Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH . '" checked=',
			$output
		);
		$this->assertStringContainsString( 'Managed pattern index using the saved Cloudflare credentials from the Embedding Model section.', $output );
		$this->assertStringContainsString(
			'Cloudflare AI Search Pattern Storage',
			$output
		);
		$this->assertStringNotContainsString( 'name="' . Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID . '"', $output );
		$this->assertStringNotContainsString( 'name="' . Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE . '"', $output );
		$this->assertStringNotContainsString( 'name="' . Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN . '"', $output );
		$this->assertStringNotContainsString(
			'name="' . Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID . '"',
			$output
		);
		$this->assertStringContainsString(
			'name="' . Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH . '"',
			$output
		);
	}

	public function test_render_page_does_not_show_duplicate_pattern_index_submit_button(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'workers-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Create managed pattern index.', $output );
		$this->assertStringNotContainsString( '>Create managed pattern index</button>', $output );
	}

	public function test_pattern_retrieval_backend_and_private_ai_search_sanitizers(): void {
		$_POST = [
			'option_page'                        => Config::OPTION_GROUP,
			'flavor_agent_settings_feedback_key' => 'pattern-backend',
		];

		$this->assertSame(
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Settings::sanitize_pattern_retrieval_backend( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH )
		);
		$this->assertSame(
			Config::PATTERN_BACKEND_QDRANT,
			Settings::sanitize_pattern_retrieval_backend( 'invalid-backend' )
		);

		$_POST = [];
		$this->assertSame(
			'pattern-index',
			Settings::sanitize_cloudflare_pattern_ai_search_instance_id( ' pattern-index ' )
		);
		$this->assertSame(
			0.75,
			Settings::sanitize_pattern_recommendation_threshold_cloudflare_ai_search( '0.754' )
		);
		$this->assertSame(
			1.0,
			Settings::sanitize_pattern_recommendation_threshold_cloudflare_ai_search( '4' )
		);

		$_POST   = [
			'option_page'                        => Config::OPTION_GROUP,
			'flavor_agent_settings_feedback_key' => 'pattern-backend',
		];
		$changed = Settings::sanitize_pattern_retrieval_backend( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH );
		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, $changed );

		$feedback = array_values( WordPressTestState::$transients )[0] ?? [];

		$this->assertTrue( (bool) ( $feedback['changed_sections']['patterns'] ?? false ) );
	}

	public function test_pattern_backend_sanitizer_does_not_provision_private_ai_search_index(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		$_POST                       = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];

		$this->assertSame(
			Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Settings::sanitize_pattern_retrieval_backend( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH )
		);
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertArrayNotHasKey( 'flavor_agent_provision_pattern_ai_search', WordPressTestState::$scheduled_events );
	}

	public function test_sanitize_private_pattern_ai_search_settings_schedules_managed_provisioning_without_remote_requests(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		$_POST                       = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
		];

		$this->assertSame(
			PatternSearchInstanceManager::managed_instance_id(),
			Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' )
		);

		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertArrayHasKey( 'flavor_agent_provision_pattern_ai_search', WordPressTestState::$scheduled_events );
		$this->assertSame(
			'provisioning',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['status'] ?? ''
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
	}

	public function test_sanitize_private_pattern_ai_search_settings_does_not_update_instance_option_during_sanitization(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'instance-old',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		$_POST                       = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
		];

		$this->assertSame(
			PatternSearchInstanceManager::managed_instance_id(),
			Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' )
		);
		$this->assertSame(
			'instance-old',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ] ?? ''
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
			WordPressTestState::$updated_options
		);
		$this->assertArrayHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE,
			WordPressTestState::$updated_options
		);
	}

	public function test_sanitize_private_pattern_ai_search_settings_warns_when_embedding_model_falls_back_for_ai_search(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/future/workers-ai-only-model',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		$_POST                       = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
		];

		Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' );

		$this->assertContains(
			[
				'setting' => Config::OPTION_GROUP,
				'code'    => 'cloudflare_pattern_ai_search_embedding_model_fallback',
				'message' => 'Cloudflare AI Search Pattern Storage will use @cf/qwen/qwen3-embedding-0.6b because the saved Embedding Model is not supported by Cloudflare AI Search.',
				'type'    => 'warning',
			],
			WordPressTestState::$settings_errors
		);
	}

	public function test_sanitize_private_pattern_ai_search_settings_schedules_managed_provisioning_once_per_save(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'instance-old',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		$_POST                       = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
		];

		$this->assertSame(
			PatternSearchInstanceManager::managed_instance_id(),
			Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' )
		);
		$this->assertSame(
			'instance-old',
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ] ?? ''
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
			WordPressTestState::$updated_options
		);
		$this->assertSame(
			$this->patternAiSearchSignature(),
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['signature'] ?? ''
		);
		$this->assertArrayHasKey(
			PatternSearchInstanceManager::PROVISION_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_private_pattern_ai_search_settings_clears_stale_provisioning_when_credentials_are_missing(): void {
		WordPressTestState::$options          = [
			'flavor_agent_cloudflare_workers_ai_account_id' => '',
			'flavor_agent_cloudflare_workers_ai_api_token' => '',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE => [
				'status'          => 'error',
				'last_error_code' => 'missing_cloudflare_pattern_ai_search_credentials',
			],
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => 'old-signature',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		WordPressTestState::$scheduled_events = [
			PatternSearchInstanceManager::PROVISION_CRON_HOOK => [
				'hook'      => PatternSearchInstanceManager::PROVISION_CRON_HOOK,
				'timestamp' => time() + 5,
				'args'      => [],
			],
		];
		$_POST                                = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
		];

		$this->assertSame(
			PatternSearchInstanceManager::managed_instance_id(),
			Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' )
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE,
			WordPressTestState::$options
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertArrayNotHasKey(
			PatternSearchInstanceManager::PROVISION_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
	}

	public function test_sanitize_private_pattern_ai_search_settings_ignores_saved_private_credentials(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'workers-account',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID => 'pattern-account',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE => 'pattern-namespace',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'instance-old',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN => 'pattern-token',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		$_POST                       = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => '',
		];

		$this->assertSame(
			PatternSearchInstanceManager::managed_instance_id(),
			Settings::sanitize_cloudflare_pattern_ai_search_instance_id( '' )
		);
		$this->assertSame(
			PatternSearchInstanceManager::credential_signature(
				'workers-account',
				'workers-token',
				'@cf/qwen/qwen3-embedding-0.6b'
			),
			WordPressTestState::$options[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE ]['signature'] ?? ''
		);
		$this->assertSame(
			[],
			WordPressTestState::$remote_get_calls
		);
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
	}

	public function test_sanitize_private_pattern_ai_search_settings_clears_stale_signature_and_schedules_revalidation(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-new',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-new',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'instance-old',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => 'old-signature',
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
		];
		$_POST                       = [
			'option_page'                            => Config::OPTION_GROUP,
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'instance-new',
		];

		$this->assertSame(
			PatternSearchInstanceManager::managed_instance_id(),
			Settings::sanitize_cloudflare_pattern_ai_search_instance_id( 'instance-new' )
		);
		$this->assertArrayNotHasKey(
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE,
			WordPressTestState::$options
		);
		$this->assertArrayHasKey(
			PatternSearchInstanceManager::PROVISION_CRON_HOOK,
			WordPressTestState::$scheduled_events
		);
		$this->assertSame( [], WordPressTestState::$remote_get_calls );
		$this->assertSame( [], WordPressTestState::$remote_post_calls );
		$this->assertStringNotContainsString( 'token-new', wp_json_encode( WordPressTestState::$settings_errors ) );
	}

	public function test_pattern_ai_search_conflict_status_uses_specific_provisioning_error_code(): void {
		WordPressTestState::$options = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE => [
				'status'          => 'error',
				'last_error_code' => 'cloudflare_pattern_ai_search_incompatible_schema',
			],
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Flavor Agent will not adopt', $output );
		$this->assertStringContainsString( PatternSearchInstanceManager::managed_instance_id(), $output );
		$this->assertStringNotContainsString( 'token-xyz', $output );
	}

	public function test_pattern_ai_search_status_panel_shows_provisioning_error_details(): void {
		WordPressTestState::$options = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_PROVISIONING_STATE => [
				'status'          => 'error',
				'last_error_code' => 'cloudflare_pattern_ai_search_instance_list_error',
				'last_error'      => 'Cloudflare AI Search instance list failed (HTTP 403): Authentication failed.',
			],
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Managed pattern index provisioning failed', $output );
		$this->assertMatchesRegularExpression(
			'/<details(?=[^>]*data-flavor-agent-status-details="cloudflare-pattern-ai-search")(?=[^>]*\bopen\b)[^>]*>/',
			$output
		);
		$this->assertStringContainsString( 'Error code: cloudflare_pattern_ai_search_instance_list_error', $output );
		$this->assertStringContainsString( 'Error message: Cloudflare AI Search instance list failed (HTTP 403): Authentication failed.', $output );
		$this->assertStringNotContainsString( 'token-xyz', $output );
	}

	public function test_pattern_ai_search_conflict_status_uses_specific_request_scoped_feedback_code(): void {
		WordPressTestState::$current_user_id = 1;
		WordPressTestState::$options         = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
		];
		$_POST                               = [
			'option_page'                        => Config::OPTION_GROUP,
			'flavor_agent_settings_feedback_key' => 'pattern-conflict',
		];

		Feedback::record_section_feedback_messages(
			Config::GROUP_PATTERNS,
			[
				[
					'tone'    => 'error',
					'message' => 'Conflict detected.',
					'code'    => 'cloudflare_pattern_ai_search_incompatible_schema',
				],
			],
			true
		);

		$feedback = WordPressTestState::$transients[ Feedback::get_storage_key( 'pattern-conflict' ) ] ?? [];

		$this->assertSame(
			'cloudflare_pattern_ai_search_incompatible_schema',
			$feedback['messages'][ Config::GROUP_PATTERNS ][0]['code'] ?? ''
		);

		$_POST = [];
		$_GET  = [
			'settings-updated'                   => 'true',
			'flavor_agent_settings_feedback_key' => 'pattern-conflict',
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Flavor Agent will not adopt', $output );
		$this->assertStringNotContainsString( 'token-xyz', $output );
	}

	public function test_pattern_ai_search_status_explains_saved_instance_signature_mismatch(): void {
		WordPressTestState::$options = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-new',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => PatternSearchInstanceManager::credential_signature(
				'account-123',
				'token-old',
				'@cf/qwen/qwen3-embedding-0.6b'
			),
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Saved managed pattern index needs re-validation for the current Embedding Model credentials. Save settings again to re-validate.',
			$output
		);
		$this->assertStringNotContainsString( 'Create managed pattern index.', $output );
	}

	public function test_page_state_requires_private_pattern_ai_search_managed_instance_id(): void {
		$base_options = [
			Config::OPTION_PATTERN_RETRIEVAL_BACKEND       => Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_VALIDATED_SIGNATURE => $this->patternAiSearchSignature(),
		];

		WordPressTestState::$options = array_merge(
			$base_options,
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'pattern-index',
			]
		);

		$state = State::get_page_state();

		$this->assertSame( Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH, $state['selected_pattern_backend'] );
		$this->assertFalse( $state['cloudflare_pattern_ai_search_configured'] );

		WordPressTestState::$options = array_merge(
			$base_options,
			[
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => PatternSearchInstanceManager::managed_instance_id(),
			]
		);

		$state = State::get_page_state();

		$this->assertTrue( $state['cloudflare_pattern_ai_search_configured'] );
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
			'flavor_agent_cloudflare_workers_ai_api_token' => 'saved-secret-key',
		];

		ob_start();
		Settings::render_text_field(
			[
				'option'      => 'flavor_agent_cloudflare_workers_ai_api_token',
				'label_for'   => 'flavor_agent_cloudflare_workers_ai_api_token',
				'type'        => 'password',
				'description' => 'Cloudflare API token used for embeddings.',
			]
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $output );
		$this->assertStringContainsString( 'value=""', $output );
		$this->assertStringContainsString( 'data-saved-secret="true"', $output );
		$this->assertStringContainsString( 'Saved', $output );
		$this->assertStringNotContainsString( 'saved-secret-key', $output );
		$this->assertStringContainsString( 'Saved value hidden. Leave blank to keep it, or enter a replacement.', $output );
	}

	public function test_register_settings_does_not_expose_block_structural_actions_toggle(): void {
		Settings::register_settings();

		// The experimental opt-out was removed when block structural actions
		// graduated to default-on; the option and its settings field are gone.
		$this->assertArrayNotHasKey(
			'flavor_agent_block_structural_actions_enabled',
			$GLOBALS['wp_registered_settings']
		);
		$this->assertArrayNotHasKey(
			'flavor_agent_block_structural_actions_enabled',
			$GLOBALS['wp_settings_fields'][ Config::PAGE_SLUG ]['flavor_agent_experimental_features']
		);
	}

	public function test_render_page_omits_retired_structural_actions_toggle(): void {
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		// The Experimental Features section still renders (it now hosts the
		// dual-logging toggle), but the retired structural opt-out must be gone.
		$this->assertStringContainsString( '6. Experimental Features', $output );
		$this->assertStringNotContainsString(
			'name="flavor_agent_block_structural_actions_enabled"',
			$output
		);
		$this->assertStringNotContainsString( 'Enable structural block actions', $output );
		$this->assertStringNotContainsString(
			'Adds review-first insert and replace actions for the selected block.',
			$output
		);
	}

	public function test_experiments_overview_status_follows_dual_logging_setting(): void {
		// The Experimental Features overview badge must reflect the only toggle
		// the section still hosts (dual logging), not the retired structural gate.
		WordPressTestState::$options['flavor_agent_dual_log_request_diagnostics'] = false;
		$this->assertSame(
			'Off',
			State::get_experiments_overview_status( State::get_page_state() )['label']
		);

		WordPressTestState::$options['flavor_agent_dual_log_request_diagnostics'] = true;
		$this->assertSame(
			'On',
			State::get_experiments_overview_status( State::get_page_state() )['label']
		);
	}

	public function test_register_settings_exposes_dual_logging_toggle(): void {
		Settings::register_settings();

		$this->assertArrayHasKey(
			'flavor_agent_dual_log_request_diagnostics',
			$GLOBALS['wp_registered_settings']
		);
		$this->assertSame(
			'boolean',
			$GLOBALS['wp_registered_settings']['flavor_agent_dual_log_request_diagnostics']['type']
		);
		$this->assertSame(
			[ Settings::class, 'sanitize_dual_log_request_diagnostics' ],
			$GLOBALS['wp_registered_settings']['flavor_agent_dual_log_request_diagnostics']['sanitize_callback']
		);
		$this->assertArrayHasKey(
			'flavor_agent_dual_log_request_diagnostics',
			$GLOBALS['wp_settings_fields'][ Config::PAGE_SLUG ]['flavor_agent_experimental_features']
		);
	}

	public function test_render_page_includes_dual_logging_toggle(): void {
		WordPressTestState::$options = [
			'flavor_agent_dual_log_request_diagnostics' => true,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'AI Activity Dual Logging', $output );
		$this->assertStringContainsString(
			'name="flavor_agent_dual_log_request_diagnostics"',
			$output
		);
		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'checked="checked"', $output );
		$this->assertStringContainsString(
			'Always record request diagnostics in the Flavor Agent activity log',
			$output
		);
	}

	public function test_sanitize_dual_log_request_diagnostics_records_change_when_disabled(): void {
		$_POST = [
			'option_page'                        => Config::OPTION_GROUP,
			'flavor_agent_settings_feedback_key' => 'experiments-dual-logging',
		];

		// Dual logging is on by default, so saving the on value is not a change.
		$this->assertTrue( Settings::sanitize_dual_log_request_diagnostics( '1' ) );
		$this->assertSame( [], WordPressTestState::$transients );

		// Turning it off differs from the default and is recorded as a change.
		$this->assertFalse( Settings::sanitize_dual_log_request_diagnostics( '0' ) );
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
		$this->assertStringContainsString( 'Use Connectors for text generation. Flavor Agent shows the active chat path here.', $screen->help_tabs[0]['content'] );
		$this->assertStringContainsString( 'Use this page for embedding credentials, pattern storage, developer-doc grounding limits, Guidelines, and AI Activity logging controls.', $screen->help_tabs[0]['content'] );
		$this->assertStringContainsString( 'When core Guidelines are available, Flavor Agent reads them first. Legacy fields remain available for migration and rollback.', $screen->help_tabs[0]['content'] );
		$this->assertSame( 'flavor-agent-configuration', $screen->help_tabs[1]['id'] );
		$this->assertStringContainsString( 'Pattern Storage chooses where the pattern catalog is indexed.', $screen->help_tabs[1]['content'] );
		$this->assertStringContainsString( 'Private AI Search pattern storage reuses the Cloudflare account and token from the Embedding Model section.', $screen->help_tabs[1]['content'] );
		$this->assertStringNotContainsString( 'Settings &gt; Connectors', $screen->help_tabs[1]['content'] );
		$this->assertSame( 'flavor-agent-troubleshooting', $screen->help_tabs[2]['id'] );
		$this->assertStringContainsString( 'Developer Docs use the built-in developer.wordpress.org grounding path.', $screen->help_tabs[2]['content'] );
		$this->assertStringContainsString( 'The Developer Docs group shows compact runtime status.', $screen->help_tabs[2]['content'] );
		$this->assertStringContainsString( 'When core Guidelines are available, Flavor Agent reads them first.', $screen->help_tabs[2]['content'] );
		$this->assertStringContainsString( 'AI Activity Dual Logging keeps Flavor Agent request diagnostics alongside core AI Request Logs when core logging is enabled.', $screen->help_tabs[2]['content'] );
		$this->assertStringNotContainsString( 'Structural block actions are beta controls.', $screen->help_tabs[2]['content'] );
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
			'Configure setup, storage, docs, and guidance.',
			$output
		);
		$this->assertStringContainsString( 'Open Activity Log', $output );
		$this->assertStringContainsString( 'Open Connectors', $output );
		$this->assertStringContainsString( 'Required', $output );
		$this->assertStringContainsString( '1. AI Model', $output );
		$this->assertStringContainsString( 'Text-generation provider status.', $output );
		$this->assertStringContainsString( 'Text generation is managed in Connectors.', $output );
		$this->assertStringContainsString( '2. Embedding Model', $output );
		$this->assertStringContainsString( 'Embedding credentials for semantic features.', $output );
		$this->assertStringContainsString( 'Cloudflare Workers AI', $output );
		$this->assertStringContainsString( 'Used for embeddings.', $output );
		$this->assertStringContainsString( '3. Patterns', $output );
		$this->assertStringContainsString( 'Storage and sync for pattern recommendations.', $output );
		$this->assertStringContainsString( 'Choose where the pattern catalog is stored.', $output );
		$this->assertStringContainsString( '4. Developer Docs', $output );
		$this->assertStringContainsString( 'Built-in developer.wordpress.org grounding is active.', $output );
		$this->assertStringContainsString( '5. Guidelines', $output );
		$this->assertStringContainsString( 'Site and block guidance.', $output );
		$this->assertStringContainsString( '6. Experimental Features', $output );
		$this->assertStringContainsString( 'AI Activity logging controls.', $output );
		$this->assertStringNotContainsString( 'Beta feature toggles.', $output );
		$this->assertStringContainsString( 'Optional', $output );
		$this->assertStringNotContainsString( 'Cloudflare Override', $output );
		$this->assertStringNotContainsString( 'Override values are live-probed before saving', $output );
		$this->assertStringNotContainsString( 'The instance must return trusted developer.wordpress.org chunks.', $output );
		$this->assertStringNotContainsString( 'Needs Account &gt; AI Search:Edit and Account &gt; AI Search:Run permissions.', esc_html( $output ) );
		$this->assertStringNotContainsString( 'Embeddings &amp; Connectors', $output );
		$this->assertStringNotContainsString( 'Set up chat first.', $output );
		$this->assertStringNotContainsString( 'Optional second step for vector-based pattern recommendations.', $output );
		$this->assertStringNotContainsString( 'Recent Activity', $output );
		$this->assertStringNotContainsString( 'Where To Configure What', $output );
		$this->assertStringNotContainsString( 'Use Connectors for shared provider credentials.', $output );
		$this->assertStringNotContainsString( 'Developer Docs Source', $output );
		$this->assertStringNotContainsString( 'Built-in public Cloudflare AI Search endpoint', $output );
		$this->assertStringNotContainsString( 'Instance:', $output );
		$this->assertStringNotContainsString( 'Runtime Grounding', $output );
		$this->assertStringNotContainsString( 'Developer Docs Prewarm', $output );
		$this->assertStringNotContainsString( 'legacy migration tooling', $output );
		$this->assertStringNotContainsString( 'JSON import/export, and rollback support', $output );
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

	public function test_render_page_reports_missing_core_ai_request_logging_storage(): void {
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'AI Activity Storage', $output );
		$this->assertStringContainsString(
			'Flavor Agent records request diagnostics in its own activity log. Upgrade to WordPress AI 1.0.0+ to access core AI request observability.',
			$output
		);
	}

	public function test_render_page_links_to_ai_settings_when_core_request_logging_is_disabled(): void {
		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		WordPressTestState::$options = [
			'wpai_features_enabled'                   => true,
			'wpai_feature_ai-request-logging_enabled' => false,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'AI Activity Storage', $output );
		$this->assertStringContainsString(
			'Flavor Agent is recording request diagnostics in its own activity log. Enable the AI Request Logging experiment in Settings &gt; AI to also capture provider, model, token, and cost data centrally.',
			$output
		);
		$this->assertStringContainsString(
			'options-general.php?page=ai-wp-admin',
			$output
		);
	}

	public function test_render_page_links_to_request_logs_and_activity_when_dual_logging_is_enabled(): void {
		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		WordPressTestState::$options = [
			'wpai_features_enabled'                   => true,
			'wpai_feature_ai-request-logging_enabled' => true,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'AI Activity Storage', $output );
		$this->assertStringContainsString(
			'AI Request Logging is enabled. Flavor Agent also records its own request diagnostics here and forwards surface, scope, and document context into each Tools &gt; AI Request Logs row (dual logging).',
			$output
		);
		$this->assertStringContainsString(
			'tools.php?page=ai-request-logs',
			$output
		);
		$this->assertStringContainsString( 'Open AI Activity', $output );
		$this->assertMatchesRegularExpression(
			'#href="[^"]*options-general\.php\?page=flavor-agent-activity"\s*>\s*Open AI Activity#',
			$output
		);
	}

	/**
	 * Loads the real plugin so flavor_agent_dual_log_request_diagnostics_enabled()
	 * exists and the dual-logging filter can flip render_page() into defer mode;
	 * without it Page.php's function_exists() guard pins $dual_logging to true.
	 * A separate process keeps the unguarded plugin require from colliding with
	 * the in-process SettingsTest cases.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_page_omits_ai_activity_link_when_dual_logging_is_disabled(): void {
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';
		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		\add_filter( 'flavor_agent_dual_log_request_diagnostics', '__return_false' );
		WordPressTestState::$options = [
			'wpai_features_enabled'                   => true,
			'wpai_feature_ai-request-logging_enabled' => true,
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'AI Request Logging is enabled. Flavor Agent defers to core logging and forwards surface, scope, and document context into each Tools &gt; AI Request Logs row.',
			$output
		);
		$this->assertStringContainsString(
			'tools.php?page=ai-request-logs',
			$output
		);
		$this->assertStringNotContainsString( 'Open AI Activity', $output );
	}

	public function test_render_page_opens_every_section_with_request_scoped_validation_errors(): void {
		WordPressTestState::$current_user_id = 1;
		$storage_key                         = Feedback::get_storage_key( 'multi-section-errors' );

		WordPressTestState::$transients[ $storage_key ] = [
			'messages'      => [
				Config::GROUP_EMBEDDINGS => [
					[
						'tone'    => 'error',
						'message' => 'Embedding validation failed.',
					],
				],
				Config::GROUP_PATTERNS   => [
					[
						'tone'    => 'error',
						'message' => 'Pattern storage validation failed.',
					],
				],
			],
			'focus_section' => Config::GROUP_EMBEDDINGS,
		];
		$_GET = [
			'settings-updated'                   => 'true',
			'flavor_agent_settings_feedback_key' => 'multi-section-errors',
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<details(?=[^>]*data-flavor-agent-section="embeddings")(?=[^>]*data-flavor-agent-validation-error="true")(?=[^>]*\bopen\b)[^>]*>/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/<details(?=[^>]*data-flavor-agent-section="patterns")(?=[^>]*data-flavor-agent-validation-error="true")(?=[^>]*\bopen\b)[^>]*>/',
			$output
		);
	}

	public function test_render_page_opens_nested_panels_with_request_scoped_validation_errors(): void {
		WordPressTestState::$current_user_id = 1;
		$storage_key                         = Feedback::get_storage_key( 'nested-panel-errors' );

		WordPressTestState::$transients[ $storage_key ] = [
			'messages'      => [
				Config::GROUP_PATTERNS   => [
					[
						'tone'    => 'error',
						'message' => 'Pattern storage validation failed.',
					],
				],
				Config::GROUP_GUIDELINES => [
					[
						'tone'    => 'error',
						'message' => 'Block guidelines validation failed.',
					],
				],
			],
			'focus_section' => Config::GROUP_PATTERNS,
		];
		$_GET = [
			'settings-updated'                   => 'true',
			'flavor_agent_settings_feedback_key' => 'nested-panel-errors',
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<details(?=[^>]*data-flavor-agent-nested-panel="pattern-ranking")(?=[^>]*data-flavor-agent-validation-error="true")(?=[^>]*\bopen\b)[^>]*>/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/<details(?=[^>]*data-flavor-agent-sync-panel)(?=[^>]*data-flavor-agent-validation-error="true")(?=[^>]*\bopen\b)[^>]*>/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/<details(?=[^>]*data-flavor-agent-nested-panel="block-guidelines")(?=[^>]*data-flavor-agent-validation-error="true")(?=[^>]*\bopen\b)[^>]*>/',
			$output
		);
	}

	public function test_sync_panel_renders_button_disabled_while_indexing(): void {
		$state                   = $this->build_default_open_group_state();
		$state['patterns_ready'] = true;
		$state['pattern_state']  = array_merge(
			PatternIndex::get_state(),
			[
				'status'         => 'indexing',
				'last_synced_at' => null,
				'indexed_count'  => 0,
				'last_error'     => null,
			]
		);
		$method                  = new \ReflectionMethod( Page::class, 'render_sync_panel' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( null, $state );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-pattern-sync-status="indexing"', $output );
		$this->assertMatchesRegularExpression(
			'/<button(?=[^>]*id="flavor-agent-sync-button")(?=[^>]*disabled="disabled")(?=[^>]*aria-disabled="true")(?=[^>]*aria-describedby="flavor-agent-sync-summary")[^>]*>/',
			$output
		);
	}

	public function test_pattern_setup_copy_points_to_embeddings_not_chat_provider(): void {
		$state                      = $this->build_default_open_group_state();
		$state['patterns_ready']    = false;
		$state['qdrant_configured'] = true;
		$state['runtime_embedding'] = [ 'configured' => false ];

		$status_blocks = State::get_section_status_blocks( Config::GROUP_PATTERNS, $state, [] );

		$this->assertSame(
			'Embedding Model is required before syncing patterns.',
			$status_blocks[0]['message'] ?? ''
		);

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Choose where the pattern catalog is stored.',
			$output
		);
		$this->assertStringNotContainsString( 'Chat Provider', $output );
	}

	public function test_pattern_setup_copy_uses_private_ai_search_when_selected(): void {
		$state                             = $this->build_default_open_group_state();
		$state['selected_pattern_backend'] = Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH;
		$state['patterns_ready']           = false;
		$state['cloudflare_pattern_ai_search_configured'] = false;
		$state['runtime_embedding']                       = [ 'configured' => true ];
		$status        = State::get_pattern_overview_status( $state );
		$status_blocks = State::get_section_status_blocks( Config::GROUP_PATTERNS, $state, [] );

		$this->assertSame( 'Needs pattern storage', $status['label'] );
		$this->assertSame(
			'Cloudflare AI Search Pattern Storage needs saved Cloudflare credentials from the Embedding Model section and a managed pattern index.',
			$status_blocks[0]['message'] ?? ''
		);
	}

	public function test_pattern_setup_copy_prioritizes_missing_embeddings_over_stale_ai_search_error(): void {
		$state                             = $this->build_default_open_group_state();
		$state['selected_pattern_backend'] = Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH;
		$state['patterns_ready']           = false;
		$state['cloudflare_pattern_ai_search_configured']   = false;
		$state['runtime_embedding']                         = [ 'configured' => false ];
		$state['cloudflare_pattern_ai_search_provisioning'] = [
			'status'          => 'error',
			'last_error_code' => 'missing_cloudflare_pattern_ai_search_credentials',
		];

		$status_blocks = State::get_section_status_blocks( Config::GROUP_PATTERNS, $state, [] );

		$this->assertSame(
			'Cloudflare AI Search Pattern Storage needs saved Cloudflare credentials from the Embedding Model section and a managed pattern index.',
			$status_blocks[0]['message'] ?? ''
		);
		$this->assertStringNotContainsString(
			'provisioning failed',
			$status_blocks[0]['message'] ?? ''
		);
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

	public function test_render_html_attributes_escapes_url_bearing_attributes_with_url_context(): void {
		ob_start();
		Utils::render_html_attributes(
			[
				'href'       => 'javascript:alert(1)',
				'src'        => 'data:text/html,unsafe',
				'data-label' => 'javascript:alert(1)',
			]
		);
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( ' href=""', $output );
		$this->assertStringContainsString( ' src=""', $output );
		$this->assertStringContainsString( ' data-label="javascript:alert(1)"', $output );
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
						'message' => 'AI model settings saved.',
					],
				],
			],
			'focus_section'    => 'chat',
		];

		$peeked = Feedback::read_settings_page_feedback( 'feedback-token', false );
		$this->assertTrue( (bool) $peeked['changed_sections']['chat'] );
		$this->assertSame( 'AI model settings saved.', $peeked['messages']['chat'][0]['message'] );
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

		$this->assertStringNotContainsString( 'AI model settings saved.', $output );
		$this->assertStringContainsString( 'Developer docs settings saved.', $output );
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

	public function test_determine_default_open_group_prioritizes_ai_model_when_chat_and_embeddings_are_missing(): void {
		$state                      = $this->build_default_open_group_state();
		$state['runtime_chat']      = [ 'configured' => false ];
		$state['runtime_embedding'] = [ 'configured' => false ];
		$state['qdrant_configured'] = false;

		$this->assertSame(
			'chat',
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_embedding_model_when_chat_is_ready_and_embeddings_are_missing(): void {
		$state                      = $this->build_default_open_group_state();
		$state['runtime_chat']      = [ 'configured' => true ];
		$state['runtime_embedding'] = [ 'configured' => false ];
		$state['qdrant_configured'] = true;

		$this->assertSame(
			'embeddings',
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_cloudflare_pattern_storage_when_selected_and_missing(): void {
		$state                             = $this->build_default_open_group_state();
		$state['runtime_chat']             = [ 'configured' => true ];
		$state['runtime_embedding']        = [ 'configured' => true ];
		$state['selected_pattern_backend'] = Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH;
		$state['cloudflare_pattern_ai_search_configured'] = false;
		$state['patterns_ready']                          = false;
		$state['qdrant_configured']                       = false;

		$this->assertSame(
			Config::GROUP_PATTERNS,
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_embedding_model_for_cloudflare_patterns_when_embeddings_are_missing(): void {
		$state                             = $this->build_default_open_group_state();
		$state['runtime_chat']             = [ 'configured' => true ];
		$state['runtime_embedding']        = [ 'configured' => false ];
		$state['selected_pattern_backend'] = Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH;
		$state['cloudflare_pattern_ai_search_configured'] = false;
		$state['patterns_ready']                          = false;
		$state['qdrant_configured']                       = false;

		$this->assertSame(
			Config::GROUP_EMBEDDINGS,
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_embedding_model_for_qdrant_when_embeddings_are_missing(): void {
		$state                             = $this->build_default_open_group_state();
		$state['runtime_chat']             = [ 'configured' => true ];
		$state['runtime_embedding']        = [ 'configured' => false ];
		$state['selected_pattern_backend'] = Config::PATTERN_BACKEND_QDRANT;
		$state['patterns_ready']           = false;
		$state['qdrant_configured']        = false;

		$this->assertSame(
			Config::GROUP_EMBEDDINGS,
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_ai_model_when_chat_is_missing_after_embeddings_are_ready(): void {
		$state                      = $this->build_default_open_group_state();
		$state['runtime_chat']      = [ 'configured' => false ];
		$state['runtime_embedding'] = [ 'configured' => true ];
		$state['qdrant_configured'] = true;

		$this->assertSame(
			'chat',
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_patterns_when_pattern_storage_is_partial(): void {
		$state                      = $this->build_default_open_group_state();
		$state['qdrant_configured'] = false;

		$this->assertSame(
			'patterns',
			State::determine_default_open_group( $state )
		);
	}

	public function test_determine_default_open_group_prioritizes_docs_for_runtime_failures(): void {
		$state                           = $this->build_default_open_group_state();
		$state['docs_configured']        = true;
		$state['runtime_docs_grounding'] = [ 'status' => 'unreachable' ];

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

	public function test_resolve_workers_ai_submission_values_invalidates_the_cache_when_the_request_payload_changes(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => '',
			'flavor_agent_cloudflare_workers_ai_api_token' => '',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => 'old-embed',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_workers_ai_account_id' => '',
			'flavor_agent_cloudflare_workers_ai_api_token' => '',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => 'first-embed',
		];

		$first_resolution = Validation::resolve_workers_ai_submission_values();

		$_POST['flavor_agent_cloudflare_workers_ai_embedding_model'] = 'second-embed';

		$second_resolution = Validation::resolve_workers_ai_submission_values();

		$this->assertSame( 'first-embed', $first_resolution['flavor_agent_cloudflare_workers_ai_embedding_model'] );
		$this->assertSame( 'second-embed', $second_resolution['flavor_agent_cloudflare_workers_ai_embedding_model'] );
	}

	public function test_blank_workers_ai_embedding_model_saves_validated_default_model(): void {
		WordPressTestState::$options              = [
			'flavor_agent_cloudflare_workers_ai_account_id' => '',
			'flavor_agent_cloudflare_workers_ai_api_token' => '',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '',
		];
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'data' => [
						[ 'embedding' => [ 0.1, 0.2, 0.3 ] ],
					],
				]
			),
		];
		$_POST                                    = [
			'option_page'                                  => Config::OPTION_GROUP,
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '',
		];

		$sanitized_model = Settings::sanitize_cloudflare_workers_ai_embedding_model( '' );
		$request_body    = json_decode( WordPressTestState::$last_remote_post['args']['body'], true );

		$this->assertSame( WorkersAIEmbeddingConfiguration::DEFAULT_MODEL, $sanitized_model );
		$this->assertSame( WorkersAIEmbeddingConfiguration::DEFAULT_MODEL, $request_body['model'] ?? '' );
	}

	public function test_submission_request_fingerprint_does_not_retain_raw_post_secrets(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_account_id' => '',
			'flavor_agent_cloudflare_workers_ai_api_token' => '',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '',
		];
		$_POST                       = [
			'option_page'                                  => 'flavor_agent_settings',
			'flavor_agent_cloudflare_workers_ai_account_id' => '',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'posted-secret-key',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '',
		];

		Validation::resolve_workers_ai_submission_values();

		$reflection = new \ReflectionClass( Validation::class );
		$property   = $reflection->getProperty( 'submission_value_cache' );
		$property->setAccessible( true );
		$state = $property->getValue();

		$fingerprint = $state['cloudflare_workers_ai']['request_fingerprint'] ?? null;

		$this->assertIsString( $fingerprint );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $fingerprint );
		$this->assertStringNotContainsString( 'posted-secret-key', $fingerprint );
	}

	public function test_render_page_uses_public_developer_docs_source_and_hides_legacy_credentials(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Built-in developer.wordpress.org grounding is active.', $output );
		$this->assertStringNotContainsString( 'Developer Docs Source', $output );
		$this->assertStringNotContainsString( 'Built-in public Cloudflare AI Search endpoint', $output );
		$this->assertStringNotContainsString( '101d836c-480b-4b39-b14e-505a6aa58f47', $output );
		$this->assertStringNotContainsString( 'Instance:', $output );
		$this->assertStringNotContainsString( 'Runtime Grounding', $output );
		$this->assertStringNotContainsString( 'Developer Docs Prewarm', $output );
		$this->assertStringNotContainsString( 'Legacy Developer Docs Cloudflare Override', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_cloudflare_ai_search_account_id"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_cloudflare_ai_search_instance_id"', $output );
		$this->assertStringNotContainsString( 'name="flavor_agent_cloudflare_ai_search_api_token"', $output );
		$this->assertStringNotContainsString( 'Saved custom developer-docs override values are present.', $output );
		$this->assertStringNotContainsString( 'Override values are live-probed before saving', $output );
		$this->assertStringNotContainsString( 'Optional override.', $output );
		$this->assertStringNotContainsString( 'Saved value exists. For security, this field is intentionally blank.', $output );
	}

	public function test_page_state_treats_wordpress_ai_client_runtime_as_ready_for_chat(): void {
		WordPressTestState::$ai_client_supported = true;

		$state = State::get_page_state();
		$meta  = State::get_group_card_meta( Config::GROUP_CHAT, $state );

		$this->assertSame( 'wordpress_ai_client', $state['runtime_chat']['provider'] ?? null );
		$this->assertTrue( $state['runtime_chat']['configured'] ?? false );
		$this->assertSame( 'Ready', $meta['status']['label'] ?? null );
		$this->assertSame( 'success', $meta['status']['tone'] ?? null );
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
			'Embedding model settings saved.',
			$other_user_output
		);
		$this->assertNotEmpty( WordPressTestState::$transients );

		WordPressTestState::$current_user_id = 1;
		ob_start();
		Settings::render_page();
		$matching_user_output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'Embedding model settings saved.',
			$matching_user_output
		);
		$this->assertSame( [], WordPressTestState::$transients );
	}

	public function test_render_page_includes_guidelines_controls(): void {
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '5. Guidelines', $output );
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

		$this->assertStringContainsString( 'Core Guidelines connected.', $output );
		$this->assertStringNotContainsString( 'legacy migration tooling', $output );
		$this->assertStringNotContainsString( 'JSON import/export, and rollback support', $output );
		$this->assertStringContainsString( 'name="flavor_agent_guideline_site"', $output );
		$this->assertStringContainsString( 'Import JSON', $output );
		$this->assertStringContainsString( 'Export JSON', $output );
	}

	public function test_render_page_does_not_output_raw_guidelines_block_options_script(): void {
		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'data-guidelines-block-options', $output );
	}

	public function test_admin_localized_data_includes_guidelines_block_options(): void {
		register_block_type(
			'core/paragraph',
			[
				'title'      => 'Paragraph',
				'attributes' => [
					'content' => [
						'role' => 'content',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'value' => 'core/paragraph',
					'label' => 'Paragraph',
				],
			],
			Assets::get_localized_data()['guidelinesBlockOptions'] ?? null
		);
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

	public function test_sanitize_reasoning_effort_accepts_xhigh(): void {
		$this->assertSame( 'xhigh', Settings::sanitize_reasoning_effort( 'xhigh' ) );
		$this->assertSame( 'medium', Settings::sanitize_reasoning_effort( 'invalid' ) );
	}

	public function test_direct_reasoning_effort_sanitizer_call_still_sanitizes_supplied_value_without_settings_post(): void {
		WordPressTestState::$options = [
			Config::OPTION_REASONING_EFFORT => 'medium',
		];
		$_POST                       = [];

		$this->assertSame( 'xhigh', Settings::sanitize_reasoning_effort( 'xhigh' ) );
		$this->assertSame( 'medium', Settings::sanitize_reasoning_effort( 'invalid' ) );
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

	public function test_get_docs_overview_status_reports_needs_attention_when_unreachable(): void {
		$result = State::get_docs_overview_status(
			[
				'docs_configured'        => true,
				'runtime_docs_grounding' => [
					'status' => 'unreachable',
				],
			]
		);

		$this->assertSame(
			[
				'label' => 'Needs attention',
				'tone'  => 'warning',
			],
			$result
		);

		$this->assertSame(
			[
				'label' => 'Ready',
				'tone'  => 'success',
			],
			State::get_docs_overview_status(
				[
					'docs_configured'        => true,
					'runtime_docs_grounding' => [ 'status' => 'ok' ],
				]
			)
		);
	}

	public function test_render_page_shows_single_unreachable_warning_in_developer_docs_group(): void {
		WordPressTestState::$options = [
			AISearchClient::RUNTIME_STATE_OPTION => [
				'status'          => 'unreachable',
				'lastSearchAt'    => '2026-06-11 00:00:00',
				'lastResultCount' => 0,
			],
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Built-in developer.wordpress.org grounding is active.', $output );
		$this->assertSame(
			1,
			substr_count( $output, 'temporarily unavailable' ),
			'unreachable backend yields exactly one docs warning'
		);
		$this->assertStringContainsString( 'Recommendations still run without it.', $output );
	}

	public function test_render_page_shows_no_docs_warning_when_runtime_state_is_ok(): void {
		WordPressTestState::$options = [
			AISearchClient::RUNTIME_STATE_OPTION => [
				'status'          => 'ok',
				'lastSearchAt'    => '2026-06-11 00:00:00',
				'lastResultCount' => 4,
			],
		];

		ob_start();
		Settings::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Built-in developer.wordpress.org grounding is active.', $output );
		$this->assertStringNotContainsString( 'temporarily unavailable', $output );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_default_open_group_state(): array {
		return [
			'runtime_chat'                            => [ 'configured' => true ],
			'selected_pattern_backend'                => Config::PATTERN_BACKEND_QDRANT,
			'pattern_state'                           => [
				'last_error' => '',
				'status'     => 'ok',
			],
			'patterns_ready'                          => true,
			'qdrant_configured'                       => true,
			'cloudflare_pattern_ai_search_configured' => false,
			'runtime_embedding'                       => [ 'configured' => true ],
			'docs_configured'                         => false,
			'prewarm_state'                           => [ 'status' => 'ok' ],
			'runtime_docs_grounding'                  => [ 'status' => 'ok' ],
		];
	}

	private function reset_validation_state(): void {
		$this->set_static_property( Validation::class, 'workers_ai_validation_state', null );
		$this->set_static_property( Validation::class, 'workers_ai_validation_error_reported', false );
		$this->set_static_property( Validation::class, 'workers_ai_dimension_warning_reported', false );
		$this->set_static_property( Validation::class, 'qdrant_validation_state', null );
		$this->set_static_property( Validation::class, 'qdrant_validation_error_reported', false );
		$this->set_static_property( Validation::class, 'pattern_ai_search_validation_state', null );
		$this->set_static_property( Validation::class, 'pattern_ai_search_validation_error_reported', false );
		$this->set_static_property( Validation::class, 'pattern_ai_search_embedding_model_warning_reported', false );
		$this->set_static_property( Validation::class, 'submission_value_cache', [] );
		$this->set_static_property( Assets::class, 'admin_assets_enqueued', false );
	}

	private function set_static_property( string $class_name, string $property_name, mixed $value ): void {
		$reflection = new \ReflectionClass( $class_name );
		$property   = $reflection->getProperty( $property_name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	private function patternAiSearchSignature(
		string $account_id = 'account-123',
		string $api_token = 'token-xyz',
		string $embedding_model = '@cf/qwen/qwen3-embedding-0.6b'
	): string {
		return PatternSearchInstanceManager::credential_signature(
			$account_id,
			$api_token,
			$embedding_model
		);
	}
}
