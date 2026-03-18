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
			'edit_posts'    => true,
			'manage_options' => true,
		];
		WordPressTestState::$options = [
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

	public function test_check_status_filters_admin_only_docs_ability_for_non_admin_users(): void {
		WordPressTestState::$capabilities = [
			'edit_posts' => true,
		];
		WordPressTestState::$options = [
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
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'gpt-5.4',
		];

		$status = InfraAbilities::check_status( [] );

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 'gpt-5.4', $status['model'] );
		$this->assertContains( 'flavor-agent/recommend-template', $status['availableAbilities'] );
		$this->assertTrue( $status['backends']['azure_openai']['configured'] );
		$this->assertSame( 'gpt-5.4', $status['backends']['azure_openai']['chatDeployment'] );
		$this->assertNull( $status['backends']['azure_openai']['embeddingDeployment'] );
	}
}
