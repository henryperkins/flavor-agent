<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\RequestLoggingBridge;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Cloudflare\PatternSearchInstanceManager;
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
		$this->assertHookRegistered( PatternSearchInstanceManager::PROVISION_CRON_HOOK, [ PatternSearchInstanceManager::class, 'process_managed_instance_provisioning' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_openai_provider', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_azure_chat_deployment', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_azure_openai_endpoint', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_openai_native_api_key', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_openai_native_chat_model', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_openai_native_embedding_model', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_connectors_ai_openai_api_key', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_workers_ai_account_id', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_workers_ai_api_token', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_workers_ai_embedding_model', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_pattern_retrieval_backend', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_account_id', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_namespace', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_instance_id', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookNotRegistered( 'update_option_flavor_agent_cloudflare_pattern_ai_search_api_token', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_flavor_agent_qdrant_url', [ PatternIndex::class, 'handle_dependency_change' ] );
		$this->assertHookRegistered( 'update_option_home', [ PatternIndex::class, 'handle_dependency_change' ] );
	}

	public function test_plugin_bootstrap_does_not_register_native_recommended_pattern_category(): void {
		do_action( 'init' );

		$this->assertArrayNotHasKey(
			'recommended',
			WordPressTestState::$registered_block_pattern_categories,
			'Pattern recommendations must render in Flavor Agent local shelves without registering a native Gutenberg pattern category.'
		);
	}

	public function test_plugin_bootstrap_does_not_reorder_native_pattern_categories(): void {
		$settings = [
			'blockPatternCategories' => [
				[
					'name'  => 'theme',
					'label' => 'Theme',
				],
				[
					'name'  => 'recommended',
					'label' => 'Recommended',
				],
				[
					'name'  => 'text',
					'label' => 'Text',
				],
			],
		];

		$this->assertSame(
			$settings,
			apply_filters( 'block_editor_settings_all', $settings ),
			'Flavor Agent must not reorder Gutenberg native pattern categories for the local recommendation shelf.'
		);
	}

	public function test_plugin_bootstrap_does_not_register_ai_feature_option_seeding_on_admin_init(): void {
		$this->assertHookRegistered( 'admin_init', [ \FlavorAgent\Settings::class, 'register_settings' ] );
		$this->assertHookNotRegistered( 'admin_init', [ \FlavorAgent\AI\FeatureBootstrap::class, 'seed_ai_feature_options' ] );

		do_action( 'admin_init' );

		$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$options );
		$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$options );
		$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$updated_options );
		$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$updated_options );
	}

	public function test_activation_does_not_seed_ai_feature_options(): void {
		WordPressTestState::$activation_hooks[ FLAVOR_AGENT_FILE ]();

		$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$options );
		$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$options );
		$this->assertArrayNotHasKey( 'wpai_features_enabled', WordPressTestState::$updated_options );
		$this->assertArrayNotHasKey( 'wpai_feature_flavor-agent_enabled', WordPressTestState::$updated_options );
	}

	public function test_activation_initializes_secret_options_without_autoload(): void {
		WordPressTestState::$options = [
			'flavor_agent_cloudflare_workers_ai_api_token' => 'existing-token',
		];

		WordPressTestState::$activation_hooks[ FLAVOR_AGENT_FILE ]();

		$this->assertSame( 'existing-token', WordPressTestState::$options['flavor_agent_cloudflare_workers_ai_api_token'] );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_workers_ai_api_token', WordPressTestState::$option_autoload );
		$this->assertSame( '', WordPressTestState::$options['flavor_agent_qdrant_key'] );
		$this->assertFalse( WordPressTestState::$option_autoload['flavor_agent_qdrant_key'] );
		$this->assertArrayNotHasKey( 'flavor_agent_cloudflare_pattern_ai_search_api_token', WordPressTestState::$options );
	}

	public function test_block_structural_actions_enabled_by_default_and_is_filterable(): void {
		$this->assertTrue( flavor_agent_block_structural_actions_enabled() );
		$this->assertTrue(
			flavor_agent_get_editor_bootstrap_data(
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				'https://example.test/wp-admin/options-connectors.php'
			)['enableBlockStructuralActions']
		);

		add_filter( 'flavor_agent_enable_block_structural_actions', '__return_false' );

		$this->assertFalse( flavor_agent_block_structural_actions_enabled() );
		$this->assertFalse(
			flavor_agent_get_editor_bootstrap_data(
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				'https://example.test/wp-admin/options-connectors.php'
			)['enableBlockStructuralActions']
		);
	}

	public function test_bootstrap_dual_logs_request_diagnostics_by_default_with_core_logging(): void {
		\add_filter( 'flavor_agent_core_request_logging_class_available', '__return_true' );
		WordPressTestState::$options['wpai_features_enabled']                   = true;
		WordPressTestState::$options['wpai_feature_ai-request-logging_enabled'] = true;

		$this->assertTrue( RequestLoggingBridge::is_core_logging_enabled() );

		// The bootstrap registers the flavor_agent_persist_request_diagnostic_with_core_logging
		// filter, so AI Activity Dual Logging is on by default: Flavor Agent keeps its own
		// request_diagnostic row alongside core's Tools > AI Request Logs.
		$this->assertTrue( RequestLoggingBridge::should_persist_request_diagnostic() );

		// Disabling the dual-logging option defers to core logging alone.
		WordPressTestState::$options['flavor_agent_dual_log_request_diagnostics'] = false;
		$this->assertFalse( RequestLoggingBridge::should_persist_request_diagnostic() );
	}

	public function test_editor_bootstrap_exposes_activity_log_url_for_admins(): void {
		WordPressTestState::$capabilities['manage_options'] = true;

		$data = flavor_agent_get_editor_bootstrap_data(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertSame(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent-activity',
			$data['activityLogUrl']
		);
	}

	public function test_editor_bootstrap_omits_activity_log_url_for_non_admins(): void {
		WordPressTestState::$capabilities['manage_options'] = false;

		$data = flavor_agent_get_editor_bootstrap_data(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertSame( '', $data['activityLogUrl'] );
	}

	public function test_activation_installs_activity_storage_and_schedules_background_work(): void {
		WordPressTestState::$options = [
			Provider::OPTION_NAME                          => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'account-123',
			'flavor_agent_cloudflare_workers_ai_api_token' => 'token-xyz',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
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
			PatternIndex::CRON_HOOK             => [ 'hook' => PatternIndex::CRON_HOOK ],
			ActivityRepository::PRUNE_CRON_HOOK => [ 'hook' => ActivityRepository::PRUNE_CRON_HOOK ],
			ActivityRepository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK => [ 'hook' => ActivityRepository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK ],
			'flavor_agent_prewarm_docs'         => [ 'hook' => 'flavor_agent_prewarm_docs' ],
			'flavor_agent_warm_docs_context'    => [ 'hook' => 'flavor_agent_warm_docs_context' ],
			PatternSearchInstanceManager::PROVISION_CRON_HOOK => [ 'hook' => PatternSearchInstanceManager::PROVISION_CRON_HOOK ],
			CoreRoadmapGuidance::WARM_CRON_HOOK => [ 'hook' => CoreRoadmapGuidance::WARM_CRON_HOOK ],
		];
		WordPressTestState::$transients['flavor_agent_sync_lock'] = time();

		WordPressTestState::$deactivation_hooks[ FLAVOR_AGENT_FILE ]();

		$this->assertSame(
			[
				PatternIndex::CRON_HOOK,
				ActivityRepository::PRUNE_CRON_HOOK,
				ActivityRepository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK,
				// Legacy docs warm crons stay cleared by literal name on deactivation.
				'flavor_agent_prewarm_docs',
				'flavor_agent_warm_docs_context',
				PatternSearchInstanceManager::PROVISION_CRON_HOOK,
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

	private function assertHookNotRegistered( string $hook_name, mixed $unexpected_callback ): void {
		$callbacks = [];

		foreach ( WordPressTestState::$filters[ $hook_name ] ?? [] as $priority_callbacks ) {
			foreach ( $priority_callbacks as $entry ) {
				$callbacks[] = $entry['callback'] ?? null;
			}
		}

		$this->assertNotContains( $unexpected_callback, $callbacks );
	}
}
