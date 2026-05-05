<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\InfraAbilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class InfraAbilitiesTest extends TestCase {

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

	public function test_check_status_marks_cloudflare_docs_backend_as_configured_without_marking_plugin_ready(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'     => true,
			'manage_options' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertNull( $status['model'] );
		$this->assertContains( 'flavor-agent/search-wordpress-docs', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_ai_search']['configured'] );
		$this->assertSame(
			'c5d54c4a-27df-4034-80da-ca6054684fcd',
			$status['backends']['cloudflare_ai_search']['instanceId']
		);
	}

	public function test_check_status_includes_new_design_helpers_for_editors(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertContains( 'flavor-agent/get-pattern', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/list-synced-patterns', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-synced-pattern', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/list-template-parts', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/list-allowed-blocks', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-active-theme', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-theme-presets', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/get-theme-styles', $status['availableAbilities'] );
	}

	public function test_check_status_keeps_list_template_parts_available_for_theme_only_users(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertContains( 'flavor-agent/list-template-parts', $status['availableAbilities'] );
		$this->assertNotContains( 'flavor-agent/get-pattern', $status['availableAbilities'] );
	}

	public function test_check_status_does_not_enable_chat_surfaces_without_selected_connector(): void {
		WordPressTestState::$capabilities        = [
			'edit_posts' => true,
		];
		WordPressTestState::$options             = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
		WordPressTestState::$ai_client_supported = true;

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertNull( $status['model'] );
		$this->assertNotContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertSame( 'block_backend_unconfigured', $status['surfaces']['block']['reason'] );
		$this->assertSame( 'connectors', $status['surfaces']['block']['owner'] );
		$this->assertTrue( $status['backends']['wordpress_ai_client']['configured'] );
	}

	public function test_check_status_filters_admin_only_docs_ability_for_non_admin_users(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertNotContains( 'flavor-agent/search-wordpress-docs', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_ai_search']['configured'] );
	}

	public function test_check_status_uses_connector_key_for_openai_native_backend(): void {
		WordPressTestState::$capabilities               = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
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
		WordPressTestState::$options                    = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'connectors_ai_openai_api_key'               => 'connector-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
			'wpai_features_enabled'                      => true,
			'wpai_feature_flavor-agent_enabled'          => true,
		];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template-part', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-navigation', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-style', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['openai_native']['configured'] );
		$this->assertSame(
			'text-embedding-3-large',
			$status['backends']['openai_native']['embeddingModel']
		);
		$this->assertSame( 'connector_database', $status['backends']['openai_native']['credentialSource'] );
		$this->assertTrue( $status['backends']['openai_native']['connectorRegistered'] );
		$this->assertTrue( $status['backends']['openai_native']['connectorConfigured'] );
		$this->assertSame( 'database', $status['backends']['openai_native']['connectorKeySource'] );
	}

	public function test_check_status_prefers_openai_env_key_over_connector_database(): void {
		putenv( 'OPENAI_API_KEY=env-key' );

		WordPressTestState::$capabilities        = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];
		WordPressTestState::$connectors          = [
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
		WordPressTestState::$options             = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'connectors_ai_openai_api_key'               => 'connector-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
			'wpai_features_enabled'                      => true,
			'wpai_feature_flavor-agent_enabled'          => true,
		];
		WordPressTestState::$ai_client_supported = true;

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['backends']['openai_native']['configured'] );
		$this->assertSame( 'env', $status['backends']['openai_native']['credentialSource'] );
		$this->assertTrue( $status['backends']['openai_native']['connectorConfigured'] );
		$this->assertSame( 'env', $status['backends']['openai_native']['connectorKeySource'] );
	}

	public function test_check_status_hides_recommendation_surfaces_when_ai_feature_is_disabled(): void {
		WordPressTestState::$capabilities               = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
			'manage_options'     => true,
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
		WordPressTestState::$options                    = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'connectors_ai_openai_api_key'               => 'connector-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
			'wpai_features_enabled'                      => true,
			'wpai_feature_flavor-agent_enabled'          => false,
		];
		WordPressTestState::$ai_client_supported        = true;
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertSame( 'ai_feature_disabled', $status['surfaces']['block']['reason'] );
		$this->assertFalse( $status['surfaces']['block']['available'] );
		$this->assertFalse( $status['surfaces']['template']['available'] );
		$this->assertFalse( $status['surfaces']['pattern']['available'] );

		foreach ( [
			'flavor-agent/recommend-block',
			'flavor-agent/recommend-content',
			'flavor-agent/recommend-patterns',
			'flavor-agent/recommend-template',
			'flavor-agent/recommend-template-part',
			'flavor-agent/recommend-navigation',
			'flavor-agent/recommend-style',
		] as $ability_id ) {
			$this->assertNotContains( $ability_id, $status['availableAbilities'] );
		}

		$this->assertContains( 'flavor-agent/introspect-block', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['openai_native']['configured'] );
	}

	public function test_check_status_uses_connector_chat_and_direct_embeddings_for_patterns(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		WordPressTestState::$options = [
			'flavor_agent_openai_provider'               => 'anthropic',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
			'connectors_ai_anthropic_api_key'            => 'anthropic-key',
			'wpai_features_enabled'                      => true,
			'wpai_feature_flavor-agent_enabled'          => true,
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

		WordPressTestState::$ai_client_supported = true;

		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertTrue( $status['surfaces']['template']['available'] );
		$this->assertTrue( $status['surfaces']['pattern']['available'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-navigation', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-style', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['openai_native']['configured'] );
		$this->assertSame(
			'text-embedding-3-large',
			$status['backends']['openai_native']['embeddingModel']
		);
	}

	public function test_check_status_reports_workers_ai_embeddings_for_patterns(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		WordPressTestState::$options = [
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'connectors_ai_openai_api_key'                 => 'connector-key',
			'wpai_features_enabled'                        => true,
			'wpai_feature_flavor-agent_enabled'            => true,
		];

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

		WordPressTestState::$ai_client_supported = true;

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertTrue( $status['surfaces']['pattern']['available'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_workers_ai']['configured'] );
		$this->assertSame(
			'@cf/qwen/qwen3-embedding-0.6b',
			$status['backends']['cloudflare_workers_ai']['embeddingModel']
		);
	}

	public function test_check_status_uses_provider_managed_model_for_selected_connector_provider(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];

		WordPressTestState::$options = [
			'flavor_agent_openai_provider'      => 'anthropic',
			'flavor_agent_qdrant_url'           => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'           => 'qdrant-key',
			'connectors_ai_anthropic_api_key'   => 'anthropic-key',
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
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

		WordPressTestState::$ai_client_supported = true;

		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertTrue( $status['surfaces']['template']['available'] );
		$this->assertTrue( $status['surfaces']['navigation']['available'] );
		$this->assertFalse( $status['surfaces']['pattern']['available'] );
		$this->assertSame( 'pattern_backend_unconfigured', $status['surfaces']['pattern']['reason'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-navigation', $status['availableAbilities'] );
		$this->assertNotContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
	}

	public function test_check_status_marks_navigation_surface_unavailable_without_theme_capability(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];
		WordPressTestState::$options      = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'gpt-5.4',
			'wpai_features_enabled'              => true,
			'wpai_feature_flavor-agent_enabled'  => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['surfaces']['navigation']['available'] );
		$this->assertFalse( $status['surfaces']['globalStyles']['available'] );
		$this->assertSame(
			'missing_theme_capability',
			$status['surfaces']['navigation']['reason']
		);
		$this->assertSame(
			'missing_theme_capability',
			$status['surfaces']['globalStyles']['reason']
		);
		$this->assertNotContains(
			'flavor-agent/recommend-navigation',
			$status['availableAbilities']
		);
		$this->assertNotContains(
			'flavor-agent/recommend-style',
			$status['availableAbilities']
		);
	}

	public function test_check_status_marks_pattern_surface_unavailable_without_plugin_backends(): void {
		WordPressTestState::$capabilities               = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];
		WordPressTestState::$options                    = [
			'flavor_agent_openai_provider'      => 'openai',
			'connectors_ai_openai_api_key'      => 'connector-key',
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
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
			'openai' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['surfaces']['pattern']['available'] );
		$this->assertSame(
			'pattern_backend_unconfigured',
			$status['surfaces']['pattern']['reason']
		);
		$this->assertSame(
			'plugin_settings',
			$status['surfaces']['pattern']['owner']
		);
		$this->assertTrue( $status['surfaces']['template']['available'] );
		$this->assertTrue( $status['surfaces']['globalStyles']['available'] );
		$this->assertNotContains(
			'flavor-agent/recommend-patterns',
			$status['availableAbilities']
		);
	}

	public function test_check_status_marks_block_surface_unavailable_without_connectors_chat(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];
		WordPressTestState::$options      = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertFalse( $status['configured'] );
		$this->assertFalse( $status['surfaces']['block']['available'] );
		$this->assertSame(
			'block_backend_unconfigured',
			$status['surfaces']['block']['reason']
		);
		$this->assertSame(
			'connectors',
			$status['surfaces']['block']['owner']
		);
		$this->assertNotContains(
			'flavor-agent/recommend-block',
			$status['availableAbilities']
		);
		$this->assertFalse( $status['surfaces']['globalStyles']['available'] );
		$this->assertSame(
			'missing_theme_capability',
			$status['surfaces']['globalStyles']['reason']
		);
	}
}
