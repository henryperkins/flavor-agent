<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/bootstrap.php';

use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase {

	private string|false $previous_openai_api_key;

	private string|false $previous_connectors_ai_openai_api_key;

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$this->previous_openai_api_key               = getenv( 'OPENAI_API_KEY' );
		$this->previous_connectors_ai_openai_api_key = getenv( 'CONNECTORS_AI_OPENAI_API_KEY' );
		putenv( 'OPENAI_API_KEY' );
		putenv( 'CONNECTORS_AI_OPENAI_API_KEY' );
	}

	protected function tearDown(): void {
		if ( false === $this->previous_openai_api_key ) {
			putenv( 'OPENAI_API_KEY' );
		} else {
			putenv( 'OPENAI_API_KEY=' . $this->previous_openai_api_key );
		}

		if ( false === $this->previous_connectors_ai_openai_api_key ) {
			putenv( 'CONNECTORS_AI_OPENAI_API_KEY' );
		} else {
			putenv( 'CONNECTORS_AI_OPENAI_API_KEY=' . $this->previous_connectors_ai_openai_api_key );
		}

		parent::tearDown();
	}

	public function test_choices_fall_back_to_direct_providers_when_connector_registry_throws(): void {
		WordPressTestState::set_connector_api_errors(
			[
				'wp_get_connectors' => 'Connector registry exploded.',
			]
		);

		$this->assertSame(
			[
				Provider::NATIVE        => 'OpenAI Native',
				'cloudflare_workers_ai' => 'Cloudflare Workers AI',
			],
			Provider::choices( 'anthropic' )
		);
	}

	public function test_openai_connector_status_falls_back_when_connector_api_throws(): void {
		WordPressTestState::set_connector_api_errors(
			[
				'wp_get_connector'           => 'Connector lookup exploded.',
				'wp_is_connector_registered' => 'Connector registration check exploded.',
			]
		);

		$status = Provider::openai_connector_status();

		$this->assertFalse( $status['registered'] );
		$this->assertFalse( $status['configured'] );
		$this->assertSame( 'OpenAI', $status['label'] );
		$this->assertSame( 'connectors_ai_openai_api_key', $status['settingName'] );
		$this->assertSame( 'none', $status['keySource'] );
		$this->assertNull( $status['credentialsUrl'] );
		$this->assertNull( $status['pluginSlug'] );
	}

	public function test_openai_connector_status_respects_upstream_connector_configured_filter(): void {
		WordPressTestState::$connectors = [
			'openai' => [
				'name'           => 'OpenAI',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'oauth',
					'setting_name' => '',
				],
			],
		];

		add_filter(
			'wpai_is_openai_connector_configured',
			static fn ( bool $configured, array $connector ): bool => 'oauth' === (string) ( $connector['authentication']['method'] ?? '' ),
			10,
			2
		);

		$status = Provider::openai_connector_status();

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'connector_filter', $status['keySource'] );
	}

	public function test_openai_connector_status_keeps_database_key_when_filter_returns_false(): void {
		WordPressTestState::$options    = [
			'connectors_ai_openai_api_key' => 'sk-saved-key',
		];
		WordPressTestState::$connectors = [
			'openai' => [
				'name'           => 'OpenAI',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];

		add_filter( 'wpai_is_openai_connector_configured', '__return_false', 10, 2 );

		$status = Provider::openai_connector_status();

		$this->assertSame( 'database', $status['keySource'], 'Saved DB key must not be suppressed by a false filter result.' );
		$this->assertTrue( $status['configured'] );
	}

	public function test_openai_connector_status_checks_environment_from_connector_setting_name(): void {
		putenv( 'CONNECTORS_AI_OPENAI_API_KEY=connector-env-key' );

		WordPressTestState::$connectors = [
			'openai' => [
				'name'           => 'OpenAI',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];

		$status = Provider::openai_connector_status();

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'env', $status['keySource'] );
	}

	public function test_chat_configuration_routes_through_wordpress_ai_client_when_a_connector_is_pinned(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors                 = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'type'           => 'ai_provider',
				'authentication' => [
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];

		$config = Provider::chat_configuration();

		$this->assertSame( 'anthropic', $config['provider'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_does_not_fall_back_when_pinned_connector_is_unsupported(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors                 = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'type'           => 'ai_provider',
				'authentication' => [
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => false,
		];

		$config = Provider::chat_configuration();

		$this->assertSame( 'anthropic', $config['provider'] );
		$this->assertSame( '', $config['model'] );
		$this->assertFalse( $config['configured'] );
	}

	public function test_chat_configuration_uses_generic_wordpress_ai_client_for_direct_embedding_provider(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => Provider::NATIVE,
		];
		WordPressTestState::$ai_client_supported = true;

		$config = Provider::chat_configuration();

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'WordPress AI Client', $config['label'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_explicit_openai_native_chat_configuration_uses_generic_wordpress_ai_client_when_supported(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => Provider::NATIVE,
		];
		WordPressTestState::$ai_client_supported = true;

		$config = Provider::chat_configuration( Provider::NATIVE );

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'WordPress AI Client', $config['label'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_explicit_openai_native_chat_configuration_falls_back_to_generic_client_when_openai_connector_is_not_supported(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => Provider::NATIVE,
		];
		WordPressTestState::$connectors                 = [
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
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => false,
		];

		$config = Provider::chat_configuration( Provider::NATIVE );

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_uses_wordpress_ai_client_for_workers_ai_embedding_selection(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => 'cloudflare_workers_ai',
		];
		WordPressTestState::$ai_client_supported = true;

		$config = Provider::chat_configuration();

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'WordPress AI Client', $config['label'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );

		$meta = Provider::active_chat_request_meta();

		$this->assertSame( 'cloudflare_workers_ai', $meta['selectedProvider'] );
		$this->assertSame( 'wordpress_ai_client', $meta['provider'] );
		$this->assertFalse( $meta['usedFallback'] );
	}

	public function test_chat_configuration_maps_openai_native_to_the_openai_connector_only(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => Provider::NATIVE,
		];
		WordPressTestState::$connectors                 = [
			'openai'    => [
				'name'           => 'OpenAI',
				'type'           => 'ai_provider',
				'authentication' => [
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
			'anthropic' => [
				'name'           => 'Anthropic',
				'type'           => 'ai_provider',
				'authentication' => [
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai'    => true,
			'anthropic' => true,
		];

		$config = Provider::chat_configuration();

		$this->assertSame( 'openai', $config['provider'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_reports_unconfigured_when_no_text_generation_provider_is_available(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME => Provider::NATIVE,
		];

		$config = Provider::chat_configuration();

		$this->assertSame( Provider::NATIVE, $config['provider'] );
		$this->assertSame( '', $config['model'] );
		$this->assertFalse( $config['configured'] );
	}

	public function test_explicit_legacy_connector_chat_configuration_does_not_fall_back_when_connector_is_unsupported(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors                 = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'type'           => 'ai_provider',
				'authentication' => [
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => false,
		];

		$config = Provider::chat_configuration( 'anthropic' );

		$this->assertSame( 'anthropic', $config['provider'] );
		$this->assertSame( '', $config['model'] );
		$this->assertFalse( $config['configured'] );
	}

	public function test_unregistered_saved_connector_pin_does_not_fall_back_to_generic_wordpress_ai_client(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors                 = [];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [];

		$config = Provider::chat_configuration();

		$this->assertSame( 'anthropic', Provider::get() );
		$this->assertSame( 'anthropic', $config['provider'] );
		$this->assertFalse( $config['configured'] );
		$this->assertSame( '', $config['endpoint'] );
		$this->assertSame( '', $config['model'] );
	}

	public function test_active_chat_request_meta_reports_matching_openai_connector_for_openai_native_selection(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => Provider::NATIVE,
		];
		WordPressTestState::$connectors                 = [
			'openai' => [
				'name'           => 'OpenAI',
				'type'           => 'ai_provider',
				'authentication' => [
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];

		Provider::chat_configuration();
		$meta = Provider::active_chat_request_meta();

		$this->assertSame( Provider::NATIVE, $meta['selectedProvider'] );
		$this->assertSame( 'openai', $meta['provider'] );
		$this->assertSame( 'OpenAI', $meta['providerLabel'] );
		$this->assertSame( 'provider-managed', $meta['model'] );
		$this->assertSame( 'openai', $meta['connectorId'] );
		$this->assertSame( 'OpenAI', $meta['connectorLabel'] );
		$this->assertSame( 'connectors', $meta['owner'] );
		$this->assertSame( 'Settings > Connectors', $meta['ownerLabel'] );
		$this->assertSame(
			'OpenAI via Settings > Connectors',
			$meta['pathLabel']
		);
		$this->assertFalse( $meta['usedFallback'] );
	}

	public function test_native_effective_api_key_metadata_prefers_env_over_connector_database(): void {
		putenv( 'OPENAI_API_KEY=env-key' );

		WordPressTestState::$options    = [
			'connectors_ai_openai_api_key' => 'connector-key',
		];
		WordPressTestState::$connectors = [
			'openai' => [
				'type'           => 'ai_provider',
				'name'           => 'OpenAI',
				'authentication' => [
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];

		$metadata = Provider::native_effective_api_key_metadata();

		$this->assertSame( 'env-key', $metadata['api_key'] );
		$this->assertSame( 'env', $metadata['source'] );
		$this->assertSame( 'env', $metadata['connector']['keySource'] );
		$this->assertTrue( $metadata['connector']['configured'] );
	}

	public function test_active_chat_request_meta_reports_connector_identity_for_connector_provider(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors          = [
			'anthropic' => [
				'type'           => 'ai_provider',
				'name'           => 'Anthropic',
				'authentication' => [
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
				'plugin'         => [
					'slug' => 'ai-services-anthropic',
				],
			],
		];
		WordPressTestState::$ai_client_supported = true;

		Provider::chat_configuration();
		$meta = Provider::active_chat_request_meta();

		$this->assertSame( 'anthropic', $meta['connectorId'] );
		$this->assertSame( 'Anthropic', $meta['connectorLabel'] );
		$this->assertSame( 'ai-services-anthropic', $meta['connectorPluginSlug'] );
	}

	public function test_active_chat_request_meta_recovers_connector_plugin_slug_from_string_metadata(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors          = [
			'anthropic' => [
				'type'           => 'ai_provider',
				'name'           => 'Anthropic',
				'authentication' => [
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
				'plugin'         => 'ai-services-anthropic/ai-services-anthropic.php',
			],
		];
		WordPressTestState::$ai_client_supported = true;

		Provider::chat_configuration();
		$meta = Provider::active_chat_request_meta();

		$this->assertSame( 'ai-services-anthropic', $meta['connectorPluginSlug'] );
	}
}
