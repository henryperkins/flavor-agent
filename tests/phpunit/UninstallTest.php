<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_uninstall_removes_plugin_owned_options_cron_transients_and_activity_table(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$option_names = [
			'flavor_agent_api_key',
			'flavor_agent_model',
			'flavor_agent_openai_provider',
			'flavor_agent_azure_openai_endpoint',
			'flavor_agent_azure_openai_key',
			'flavor_agent_azure_embedding_deployment',
			'flavor_agent_azure_chat_deployment',
			'flavor_agent_azure_reasoning_effort',
			'flavor_agent_openai_native_api_key',
			'flavor_agent_openai_native_embedding_model',
			'flavor_agent_qdrant_url',
			'flavor_agent_qdrant_key',
			'flavor_agent_pattern_recommendation_threshold',
			'flavor_agent_pattern_max_recommendations',
			'flavor_agent_cloudflare_ai_search_account_id',
			'flavor_agent_cloudflare_ai_search_instance_id',
			'flavor_agent_cloudflare_ai_search_api_token',
			'flavor_agent_cloudflare_ai_search_max_results',
			'flavor_agent_pattern_index_state',
			'flavor_agent_docs_prewarm_state',
			'flavor_agent_docs_runtime_state',
			'flavor_agent_docs_warm_queue',
			'flavor_agent_activity_schema_version',
			'flavor_agent_activity_retention_days',
			'flavor_agent_activity_admin_projection_backfill_cursor',
			'flavor_agent_guideline_site',
			'flavor_agent_guideline_copy',
			'flavor_agent_guideline_images',
			'flavor_agent_guideline_additional',
			'flavor_agent_guideline_blocks',
			'flavor_agent_guidelines_migration_status',
		];

		WordPressTestState::$options                                       = array_fill_keys( $option_names, 'seeded' );
		WordPressTestState::$transients                                    = [
			'flavor_agent_sync_lock'                => 1,
			'flavor_agent_core_roadmap_guidance_v1' => [],
			'flavor_agent_core_roadmap_guidance_schedule_lock' => 1,
		];
		WordPressTestState::$scheduled_events                              = [
			'flavor_agent_reindex_patterns'           => [ 'hook' => 'flavor_agent_reindex_patterns' ],
			'flavor_agent_prune_activity'             => [ 'hook' => 'flavor_agent_prune_activity' ],
			'flavor_agent_backfill_activity_admin_projection' => [ 'hook' => 'flavor_agent_backfill_activity_admin_projection' ],
			'flavor_agent_prewarm_docs'               => [ 'hook' => 'flavor_agent_prewarm_docs' ],
			'flavor_agent_warm_docs_context'          => [ 'hook' => 'flavor_agent_warm_docs_context' ],
			'flavor_agent_warm_core_roadmap_guidance' => [ 'hook' => 'flavor_agent_warm_core_roadmap_guidance' ],
		];
		WordPressTestState::$db_tables[ ActivityRepository::table_name() ] = [
			[
				'id'          => 1,
				'activity_id' => 'activity-1',
			],
		];

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( $option_names as $option_name ) {
			$this->assertArrayNotHasKey( $option_name, WordPressTestState::$options );
		}

		$this->assertSame( [], WordPressTestState::$transients );
		$this->assertArrayNotHasKey( ActivityRepository::table_name(), WordPressTestState::$db_tables );
		$this->assertSame(
			[
				'flavor_agent_reindex_patterns',
				'flavor_agent_prune_activity',
				'flavor_agent_backfill_activity_admin_projection',
				'flavor_agent_prewarm_docs',
				'flavor_agent_warm_docs_context',
				'flavor_agent_warm_core_roadmap_guidance',
			],
			WordPressTestState::$cleared_cron_hooks
		);
	}
}
