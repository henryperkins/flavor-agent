<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\InfraAbilities;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class InfraAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_check_status_marks_cloudflare_docs_backend_as_configured(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'     => true,
			'manage_options' => true,
		];
		WordPressTestState::$options      = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertNull( $status['model'] );
		$this->assertContains( 'flavor-agent/search-wordpress-docs', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['cloudflare_ai_search']['configured'] );
		$this->assertSame( 'wp-dev-docs', $status['backends']['cloudflare_ai_search']['instanceId'] );
	}

	public function test_check_status_marks_wordpress_ai_client_backend_as_configured(): void {
		WordPressTestState::$capabilities        = [
			'edit_posts' => true,
		];
		WordPressTestState::$ai_client_supported = true;

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'provider-managed', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['wordpress_ai_client']['configured'] );
	}

	public function test_check_status_filters_admin_only_docs_ability_for_non_admin_users(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];
		WordPressTestState::$options      = [
			'flavor_agent_cloudflare_ai_search_account_id' => 'account-123',
			'flavor_agent_cloudflare_ai_search_instance_id' => 'wp-dev-docs',
			'flavor_agent_cloudflare_ai_search_api_token'  => 'token-xyz',
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertNotContains( 'flavor-agent/search-wordpress-docs', $status['availableAbilities'] );
	}

	public function test_check_status_uses_azure_chat_deployment_as_primary_model_when_anthropic_is_missing(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];
		WordPressTestState::$options      = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'gpt-5.4',
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'gpt-5.4', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template-part', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['azure_openai']['configured'] );
		$this->assertSame( 'gpt-5.4', $status['backends']['azure_openai']['chatDeployment'] );
		$this->assertNull( $status['backends']['azure_openai']['embeddingDeployment'] );
	}

	public function test_check_status_uses_openai_native_model_for_active_provider(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];
		WordPressTestState::$options      = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'native-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_openai_native_chat_model'      => 'gpt-5.4',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'gpt-5.4', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template-part', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['openai_native']['configured'] );
		$this->assertSame( 'gpt-5.4', $status['backends']['openai_native']['chatModel'] );
		$this->assertSame(
			'text-embedding-3-large',
			$status['backends']['openai_native']['embeddingModel']
		);
		$this->assertSame( 'plugin_override', $status['backends']['openai_native']['credentialSource'] );
		$this->assertFalse( $status['backends']['openai_native']['connectorRegistered'] );
		$this->assertFalse( $status['backends']['openai_native']['connectorConfigured'] );
		$this->assertNull( $status['backends']['openai_native']['connectorKeySource'] );
	}

	public function test_check_status_uses_connector_key_for_openai_native_backend(): void {
		WordPressTestState::$capabilities = [
			'edit_posts'         => true,
			'edit_theme_options' => true,
		];
		WordPressTestState::$connectors   = [
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
		WordPressTestState::$options      = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'connectors_ai_openai_api_key'               => 'connector-key',
			'flavor_agent_openai_native_embedding_model' => 'text-embedding-3-large',
			'flavor_agent_openai_native_chat_model'      => 'gpt-5.4',
			'flavor_agent_qdrant_url'                    => 'https://example.cloud.qdrant.io:6333',
			'flavor_agent_qdrant_key'                    => 'qdrant-key',
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'gpt-5.4', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-block', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-template-part', $status['availableAbilities'] );
		$this->assertContains( 'flavor-agent/recommend-patterns', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['openai_native']['configured'] );
		$this->assertSame( 'gpt-5.4', $status['backends']['openai_native']['chatModel'] );
		$this->assertSame(
			'text-embedding-3-large',
			$status['backends']['openai_native']['embeddingModel']
		);
		$this->assertSame( 'connector_database', $status['backends']['openai_native']['credentialSource'] );
		$this->assertTrue( $status['backends']['openai_native']['connectorRegistered'] );
		$this->assertTrue( $status['backends']['openai_native']['connectorConfigured'] );
		$this->assertSame( 'database', $status['backends']['openai_native']['connectorKeySource'] );
	}
}
