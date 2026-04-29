<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/bootstrap.php';

use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase {

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

	public function test_choices_fall_back_to_direct_providers_when_connector_registry_throws(): void {
		WordPressTestState::set_connector_api_errors(
			[
				'wp_get_connectors' => 'Connector registry exploded.',
			]
		);

		$this->assertSame(
			[
				Provider::AZURE  => 'Azure OpenAI',
				Provider::NATIVE => 'OpenAI Native',
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

	public function test_chat_configuration_falls_back_to_generic_wordpress_ai_client_when_pinned_connector_is_unreadable(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::set_connector_api_errors(
			[
				'wp_get_connectors' => 'Connector registry exploded.',
			]
		);
		WordPressTestState::$ai_client_supported = true;

		$config = Provider::chat_configuration();

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_returns_wordpress_ai_client_when_supported(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => Provider::AZURE,
		];
		WordPressTestState::$ai_client_supported = true;

		$config = Provider::chat_configuration();

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'WordPress AI Client', $config['label'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_reports_unconfigured_when_no_text_generation_provider_is_available(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME => Provider::AZURE,
		];

		$config = Provider::chat_configuration();

		$this->assertSame( Provider::AZURE, $config['provider'] );
		$this->assertSame( '', $config['model'] );
		$this->assertFalse( $config['configured'] );
	}

	public function test_active_chat_request_meta_reports_connectors_owner_when_the_generic_ai_client_overrides_a_direct_selection(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => Provider::AZURE,
		];
		WordPressTestState::$ai_client_supported = true;

		Provider::chat_configuration();
		$meta = Provider::active_chat_request_meta();

		$this->assertSame( Provider::AZURE, $meta['selectedProvider'] );
		$this->assertSame( 'wordpress_ai_client', $meta['provider'] );
		$this->assertSame( 'WordPress AI Client', $meta['providerLabel'] );
		$this->assertSame( 'provider-managed', $meta['model'] );
		$this->assertSame( 'wordpress_ai_client', $meta['connectorId'] );
		$this->assertSame( 'WordPress AI Client', $meta['connectorLabel'] );
		$this->assertSame( 'connectors', $meta['owner'] );
		$this->assertSame( 'Settings > Connectors', $meta['ownerLabel'] );
		$this->assertSame(
			'WordPress AI Client via Settings > Connectors',
			$meta['pathLabel']
		);
		$this->assertTrue( $meta['usedFallback'] );
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
