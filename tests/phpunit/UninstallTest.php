<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Admin\Settings\Registrar;
use FlavorAgent\Tests\Support\WordPressTestState;
use FlavorAgent\UninstallOptions;
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

		$option_names = UninstallOptions::names();

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

	public function test_uninstall_option_list_covers_registered_plugin_settings(): void {
		Registrar::register_settings();

		$uninstall_option_names = UninstallOptions::names();

		foreach ( $GLOBALS['wp_registered_settings'] as $option_name => $setting ) {
			if ( Config::OPTION_GROUP !== ( $setting['option_group'] ?? '' ) ) {
				continue;
			}

			$this->assertContains(
				$option_name,
				$uninstall_option_names,
				sprintf( 'Registered setting %s should be deleted on uninstall.', $option_name )
			);
		}
	}

	public function test_uninstall_option_list_includes_workers_ai_embedding_options(): void {
		$this->assertContains( 'flavor_agent_cloudflare_workers_ai_account_id', UninstallOptions::names() );
		$this->assertContains( 'flavor_agent_cloudflare_workers_ai_api_token', UninstallOptions::names() );
		$this->assertContains( 'flavor_agent_cloudflare_workers_ai_embedding_model', UninstallOptions::names() );
	}
}
