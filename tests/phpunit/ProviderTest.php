<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/bootstrap.php';

use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	protected function tearDown(): void {
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
				'cloudflare_workers_ai' => 'Cloudflare Workers AI',
			],
			Provider::choices( 'anthropic' )
		);
	}

	public function test_provider_choices_do_not_include_connectors_for_embedding_selection(): void {
		WordPressTestState::$connectors                 = [
			'anthropic' => [
				'name'           => 'Anthropic',
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

		$this->assertSame(
			[
				'cloudflare_workers_ai' => 'Cloudflare Workers AI',
			],
			Provider::choices( 'anthropic' )
		);
	}

	public function test_chat_configuration_ignores_saved_connector_provider_and_uses_wordpress_ai_client(): void {
		WordPressTestState::$options                        = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors                     = [
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
			WordPressTestState::$ai_client_supported        = true;

			$config = Provider::chat_configuration();

			$this->assertSame( 'cloudflare_workers_ai', Provider::get() );
			$this->assertSame( 'wordpress_ai_client', $config['provider'] );
			$this->assertSame( 'provider-managed', $config['model'] );
			$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_ignores_unsupported_saved_connector_provider(): void {
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

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_uses_generic_wordpress_ai_client_for_direct_embedding_provider(): void {
		WordPressTestState::$options             = [
			Provider::OPTION_NAME => 'openai_native',
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
			Provider::OPTION_NAME => 'openai_native',
		];
		WordPressTestState::$ai_client_supported = true;

		$config = Provider::chat_configuration( 'openai_native' );

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'WordPress AI Client', $config['label'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_explicit_openai_native_chat_configuration_falls_back_to_generic_client_when_openai_connector_is_not_supported(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'openai_native',
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

		$config = Provider::chat_configuration( 'openai_native' );

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

	public function test_chat_configuration_ignores_saved_openai_native_provider(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'openai_native',
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

		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertSame( 'provider-managed', $config['model'] );
		$this->assertTrue( $config['configured'] );
	}

	public function test_chat_configuration_reports_unconfigured_when_no_text_generation_provider_is_available(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME => 'openai_native',
		];

		$config = Provider::chat_configuration();

		$this->assertSame( 'cloudflare_workers_ai', $config['provider'] );
		$this->assertSame( '', $config['model'] );
		$this->assertFalse( $config['configured'] );
	}

	public function test_explicit_connector_chat_configuration_does_not_fall_back_when_connector_is_unsupported(): void {
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

	public function test_unregistered_saved_connector_provider_is_ignored(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'anthropic',
		];
		WordPressTestState::$connectors                 = [];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [];

		$config = Provider::chat_configuration();

		$this->assertSame( 'cloudflare_workers_ai', Provider::get() );
		$this->assertSame( 'wordpress_ai_client', $config['provider'] );
		$this->assertTrue( $config['configured'] );
		$this->assertSame( 'provider-managed', $config['model'] );
	}

	public function test_active_chat_request_meta_ignores_saved_openai_native_selection(): void {
		WordPressTestState::$options                        = [
			Provider::OPTION_NAME => 'openai_native',
		];
		WordPressTestState::$connectors                     = [
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
			WordPressTestState::$ai_client_supported        = true;

			Provider::chat_configuration();
			$meta = Provider::active_chat_request_meta();

			$this->assertSame( 'cloudflare_workers_ai', $meta['selectedProvider'] );
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
		$this->assertFalse( $meta['usedFallback'] );
	}

	public function test_active_chat_request_meta_ignores_saved_connector_provider(): void {
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

		$this->assertSame( 'wordpress_ai_client', $meta['connectorId'] );
		$this->assertSame( 'WordPress AI Client', $meta['connectorLabel'] );
		$this->assertSame( '', $meta['connectorPluginSlug'] );
	}

	public function test_active_chat_request_meta_ignores_saved_connector_string_metadata(): void {
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

		$this->assertSame( 'wordpress_ai_client', $meta['connectorId'] );
		$this->assertSame( '', $meta['connectorPluginSlug'] );
	}
}
