<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;
use FlavorAgent\Support\CoreRoadmapGuidance;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PluginLifecycleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';
	}

	public function test_plugin_bootstrap_registers_lifecycle_and_dependency_hooks(): void {
		$this->assertArrayHasKey( FLAVOR_AGENT_FILE, WordPressTestState::$activation_hooks );
		$this->assertArrayHasKey( FLAVOR_AGENT_FILE, WordPressTestState::$deactivation_hooks );
		$this->assertHookRegistered( 'rest_api_init', [ \FlavorAgent\REST\Agent_Controller::class, 'register_routes' ] );
		$this->assertHookRegistered( 'admin_init', [ \FlavorAgent\Settings::class, 'register_settings' ] );
		$this->assertHookRegistered( PatternIndex::CRON_HOOK, [ PatternIndex::class, 'sync' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_azure_openai_endpoint', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_workers_ai_account_id', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_workers_ai_api_token', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_workers_ai_embedding_model', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_pattern_retrieval_backend', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_account_id', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_namespace', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_instance_id', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_api_token', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_qdrant_url', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_home', [ PatternIndex::class, 'handle_dependency_change' ] );
	}

	public function test_block_structural_actions_rollout_flag_defaults_off_and_is_filterable(): void {
		$this->assertFalse( FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS );
		$this->assertFalse( flavor_agent_block_structural_actions_enabled() );
		$this->assertFalse(
			flavor_agent_get_editor_bootstrap_data(
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				'https://example.test/wp-admin/options-connectors.php'
			)['enableBlockStructuralActions']
		);

		add_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' );

		$this->assertTrue( flavor_agent_block_structural_actions_enabled() );
		$this->assertTrue(
			flavor_agent_get_editor_bootstrap_data(
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				'https://example.test/wp-admin/options-connectors.php'
			)['enableBlockStructuralActions']
		);
	}

	public function test_activation_installs_activity_storage_and_schedules_background_work(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => Provider::AZURE,
			'flavor_agent_azure_openai_endpoint'           => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'                => 'azure-key',
			'flavor_agent_azure_embedding_deployment'      => 'embed-deployment',
			'flavor_agent_qdrant_url'                      => 'https://qdrant.example.test:6333',
			'flavor_agent_qdrant_key'                      => 'qdrant-key',
			'flavor_agent_docs_prewarm_state'              => [],
			'flavor_agent_cloudflare_ai_search_account_id' => '',
			'flavor_agent_cloudflare_ai_search_instance_id' => '',
			'flavor_agent_cloudflare_ai_search_api_token'  => '',
		];

		WordPressTestState::$activation_hooks[ FLAVOR_AGENT_FILE ]();

		$this->assertArrayHasKey( ActivityRepository::table_name(), WordPressTestState::$db_tables );
		$this->assertSame(
			ActivityRepository::SCHEMA_VERSION,
			WordPressTestState::$options[ ActivityRepository::SCHEMA_OPTION ] ?? null
		);
		$this->assertArrayHasKey( ActivityRepository::PRUNE_CRON_HOOK, WordPressTestState::$scheduled_events );
		$this->assertArrayHasKey( PatternIndex::CRON_HOOK, WordPressTestState::$scheduled_events );
	}

	public function test_deactivation_clears_all_plugin_cron_hooks_and_pattern_lock(): void {
		WordPressTestState::$scheduled_events                     = [
			PatternIndex::CRON_HOOK                => [ 'hook' => PatternIndex::CRON_HOOK ],
			ActivityRepository::PRUNE_CRON_HOOK    => [ 'hook' => ActivityRepository::PRUNE_CRON_HOOK ],
			ActivityRepository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK => [ 'hook' => ActivityRepository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK ],
			AISearchClient::PREWARM_CRON_HOOK      => [ 'hook' => AISearchClient::PREWARM_CRON_HOOK ],
			AISearchClient::CONTEXT_WARM_CRON_HOOK => [ 'hook' => AISearchClient::CONTEXT_WARM_CRON_HOOK ],
			CoreRoadmapGuidance::WARM_CRON_HOOK    => [ 'hook' => CoreRoadmapGuidance::WARM_CRON_HOOK ],
		];
		WordPressTestState::$transients['flavor_agent_sync_lock'] = time();

		WordPressTestState::$deactivation_hooks[ FLAVOR_AGENT_FILE ]();

		$this->assertSame(
			[
				PatternIndex::CRON_HOOK,
				ActivityRepository::PRUNE_CRON_HOOK,
				ActivityRepository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK,
				AISearchClient::PREWARM_CRON_HOOK,
				AISearchClient::CONTEXT_WARM_CRON_HOOK,
				CoreRoadmapGuidance::WARM_CRON_HOOK,
			],
			WordPressTestState::$cleared_cron_hooks
		);
		$this->assertArrayNotHasKey( 'flavor_agent_sync_lock', WordPressTestState::$transients );
	}

	private function assertHookRegistered( string $hook_name, callable $expected_callback ): void {
		$callbacks = [];

		foreach ( WordPressTestState::$filters[ $hook_name ] ?? [] as $priority_callbacks ) {
			foreach ( $priority_callbacks as $entry ) {
				$callbacks[] = $entry['callback'] ?? null;
			}
		}

		$this->assertContains( $expected_callback, $callbacks );
	}
}
