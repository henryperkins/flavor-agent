<?php

/**
 * Plugin Name: Flavor Agent
 * Description: Governed AI changes for WordPress: bounded operations, review-gated structural changes, server-side attribution, and drift-safe undo.
 * Version: 0.1.0
 * Author: Lakefront Digital
 * Text Domain: flavor-agent
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: ai
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FLAVOR_AGENT_VERSION', '0.1.0' );
define( 'FLAVOR_AGENT_FILE', __FILE__ );
define( 'FLAVOR_AGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLAVOR_AGENT_URL', plugin_dir_url( __FILE__ ) );

require_once FLAVOR_AGENT_DIR . 'vendor/autoload.php';

add_filter( 'wpai_default_feature_classes', [ FlavorAgent\AI\FeatureBootstrap::class, 'register_feature_class' ] );
add_action( 'admin_notices', [ FlavorAgent\AI\FeatureBootstrap::class, 'render_missing_contract_notice' ] );
add_action( 'mcp_adapter_init', [ FlavorAgent\MCP\ServerBootstrap::class, 'register' ] );

register_activation_hook(
	FLAVOR_AGENT_FILE,
	function () {
		FlavorAgent\Activity\Repository::install();
		add_option( 'flavor_agent_cloudflare_workers_ai_api_token', '', '', false );
		add_option( 'flavor_agent_qdrant_key', '', '', false );
		FlavorAgent\Activity\Repository::ensure_prune_schedule();
		FlavorAgent\Patterns\PatternIndex::activate();
		FlavorAgent\Cloudflare\AISearchClient::schedule_prewarm();
		FlavorAgent\Support\CoreRoadmapGuidance::schedule_warm();
	}
);
register_deactivation_hook(
	FLAVOR_AGENT_FILE,
	function () {
		FlavorAgent\Patterns\PatternIndex::deactivate();
		wp_clear_scheduled_hook( FlavorAgent\Activity\Repository::PRUNE_CRON_HOOK );
		wp_clear_scheduled_hook( FlavorAgent\Activity\Repository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK );
		wp_clear_scheduled_hook( FlavorAgent\Cloudflare\AISearchClient::PREWARM_CRON_HOOK );
		wp_clear_scheduled_hook( FlavorAgent\Cloudflare\AISearchClient::CONTEXT_WARM_CRON_HOOK );
		wp_clear_scheduled_hook( FlavorAgent\Cloudflare\PatternSearchInstanceManager::PROVISION_CRON_HOOK );
		wp_clear_scheduled_hook( FlavorAgent\Support\CoreRoadmapGuidance::WARM_CRON_HOOK );
	}
);

add_action( 'init', [ FlavorAgent\Activity\Repository::class, 'maybe_install' ], 5 );
add_action( 'init', [ FlavorAgent\Activity\RequestLoggingBridge::class, 'register' ], 5 );
// When the WordPress AI plugin's "AI Request Logging" experiment is enabled the
// bridge defaults to deferring to core logging. The "AI Activity Dual Logging"
// setting (Settings > Flavor Agent > Experimental Features, on by default) opts
// back in so the Settings > AI Activity audit log keeps recording its own request
// diagnostics alongside core's Tools > AI Request Logs. See
// inc/Activity/RequestLoggingBridge.php::should_persist_request_diagnostic().
add_filter(
	'flavor_agent_persist_request_diagnostic_with_core_logging',
	static function ( $persist ) {
		return flavor_agent_parse_boolean_flag( $persist ) || flavor_agent_dual_log_request_diagnostics_enabled();
	}
);
add_action( 'init', [ FlavorAgent\Activity\Repository::class, 'ensure_prune_schedule' ], 6 );
add_action( 'init', [ FlavorAgent\Cloudflare\AISearchClient::class, 'schedule_prewarm' ], 7 );
// Roadmap warm only needs to be scheduled in admin/REST/cron contexts;
// front-end page loads don't consume this guidance. Function/constant
// guards keep this safe to load from test bootstraps that don't define
// the WP context helpers.
if (
	( function_exists( 'is_admin' ) && is_admin() )
	|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
	|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
	|| ( defined( 'WP_CLI' ) && WP_CLI )
) {
	add_action( 'init', [ FlavorAgent\Support\CoreRoadmapGuidance::class, 'schedule_warm' ], 8, 0 );
}
// Helper abilities and audit/sync routes are infra, not AI-feature-gated.
add_action( 'rest_api_init', [ FlavorAgent\REST\Agent_Controller::class, 'register_routes' ] );
add_action( 'admin_enqueue_scripts', [ FlavorAgent\Settings::class, 'maybe_enqueue_admin_assets' ] );
add_action( 'admin_menu', [ FlavorAgent\Admin\ActivityPage::class, 'add_menu' ] );
add_action( 'admin_menu', [ FlavorAgent\Settings::class, 'add_menu' ] );
add_action( 'admin_init', [ FlavorAgent\Settings::class, 'register_settings' ] );
add_action(
	FlavorAgent\Activity\Repository::ADMIN_PROJECTION_BACKFILL_CRON_HOOK,
	[ FlavorAgent\Activity\Repository::class, 'run_admin_projection_backfill' ]
);
add_action( 'wp_abilities_api_categories_init', [ FlavorAgent\AI\FeatureBootstrap::class, 'register_global_ability_category' ] );
add_action( 'wp_abilities_api_init', [ FlavorAgent\AI\FeatureBootstrap::class, 'register_global_helper_abilities' ] );

// Pattern index lifecycle hooks.
add_action( FlavorAgent\Patterns\PatternIndex::CRON_HOOK, [ FlavorAgent\Patterns\PatternIndex::class, 'sync' ] );
add_action( FlavorAgent\Cloudflare\PatternSearchInstanceManager::PROVISION_CRON_HOOK, [ FlavorAgent\Cloudflare\PatternSearchInstanceManager::class, 'process_managed_instance_provisioning' ] );
add_action( 'after_switch_theme', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );
add_action( 'activated_plugin', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );
add_action( 'deactivated_plugin', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );
add_action( 'upgrader_process_complete', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );
add_action( 'save_post_wp_block', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_synced_pattern_change' ], 10, 3 );
add_action( 'before_delete_post', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_synced_pattern_change' ], 10, 2 );
add_action( 'trashed_post', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_synced_pattern_change' ], 10, 1 );
add_action( 'untrashed_post', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_synced_pattern_change' ], 10, 1 );

foreach (
	[
		'flavor_agent_cloudflare_workers_ai_account_id',
		'flavor_agent_cloudflare_workers_ai_api_token',
		'flavor_agent_cloudflare_workers_ai_embedding_model',
		'flavor_agent_pattern_retrieval_backend',
		'flavor_agent_cloudflare_pattern_ai_search_instance_id',
		'flavor_agent_qdrant_url',
		'flavor_agent_qdrant_key',
		'home',
	] as $flavor_agent_option_name
) {
	add_action(
		"update_option_{$flavor_agent_option_name}",
		[ FlavorAgent\Patterns\PatternIndex::class, 'handle_dependency_change' ],
		10,
		3
	);
}

// Docs grounding prewarm cron hook.
add_action(
	FlavorAgent\Cloudflare\AISearchClient::PREWARM_CRON_HOOK,
	[ FlavorAgent\Cloudflare\AISearchClient::class, 'prewarm' ]
);
add_action(
	FlavorAgent\Cloudflare\AISearchClient::CONTEXT_WARM_CRON_HOOK,
	[ FlavorAgent\Cloudflare\AISearchClient::class, 'process_context_warm_queue' ]
);
add_action(
	FlavorAgent\Support\CoreRoadmapGuidance::WARM_CRON_HOOK,
	[ FlavorAgent\Support\CoreRoadmapGuidance::class, 'warm' ],
	10,
	0
);
add_action(
	FlavorAgent\Activity\Repository::PRUNE_CRON_HOOK,
	[ FlavorAgent\Activity\Repository::class, 'prune' ]
);

function flavor_agent_enqueue_editor(): void {
	if ( ! FlavorAgent\AI\FeatureBootstrap::editor_runtime_available() ) {
		return;
	}

	$asset_path = FLAVOR_AGENT_DIR . 'build/index.asset.php';
	if ( ! file_exists( $asset_path ) ) {
		return;
	}

	$asset    = include $asset_path;
	$css_path = FLAVOR_AGENT_DIR . 'build/index.css';

	wp_enqueue_script_module(
		'@flavor-agent/abilities-bridge',
		FLAVOR_AGENT_URL . 'assets/abilities-bridge.js',
		[ '@wordpress/core-abilities', '@wordpress/abilities' ],
		FLAVOR_AGENT_VERSION
	);

	wp_enqueue_script(
		'flavor-agent-editor',
		FLAVOR_AGENT_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'flavor-agent-editor',
			FLAVOR_AGENT_URL . 'build/index.css',
			[],
			$asset['version']
		);
	}

	$settings_url   = admin_url( 'options-general.php?page=flavor-agent' );
	$connectors_url = admin_url( 'options-connectors.php' );

	wp_localize_script(
		'flavor-agent-editor',
		'flavorAgentData',
		flavor_agent_get_editor_bootstrap_data( $settings_url, $connectors_url )
	);
}

function flavor_agent_block_structural_actions_enabled(): bool {
	// Block structural actions graduated from their experimental opt-out on
	// 2026-06-03 and are unconditionally on. The
	// flavor_agent_enable_block_structural_actions filter is retained as a
	// runtime kill-switch (e.g. an emergency opt-out) and is now the only control.
	return flavor_agent_parse_boolean_flag(
		apply_filters( 'flavor_agent_enable_block_structural_actions', true )
	);
}

function flavor_agent_dual_log_request_diagnostics_enabled(): bool {
	return flavor_agent_parse_boolean_flag(
		apply_filters(
			'flavor_agent_dual_log_request_diagnostics',
			get_option( FlavorAgent\Admin\Settings\Config::OPTION_DUAL_LOG_REQUEST_DIAGNOSTICS, true )
		)
	);
}

function flavor_agent_parse_boolean_flag( mixed $value ): bool {
	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_int( $value ) ) {
		return 1 === $value;
	}

	if ( is_float( $value ) ) {
		return 1.0 === $value;
	}

	if ( is_string( $value ) ) {
		return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	return false;
}

/**
 * @return array<string, mixed>
 */
function flavor_agent_get_editor_bootstrap_data(
	string $settings_url,
	string $connectors_url
): array {
	$surface_capabilities = flavor_agent_get_editor_surface_capabilities(
		$settings_url,
		$connectors_url
	);
	$can_manage_settings  = current_user_can( 'manage_options' );

	return [
		'settingsUrl'                  => $settings_url,
		'connectorsUrl'                => $connectors_url,
		'connectorApprovalUrl'         => $can_manage_settings
			? FlavorAgent\LLM\WordPressAIClient::connector_approval_admin_url()
			: '',
		'activityLogUrl'               => $can_manage_settings ? admin_url( 'options-general.php?page=flavor-agent-activity' ) : '',
		'canManageFlavorAgentSettings' => $can_manage_settings,
		'enableBlockStructuralActions' => flavor_agent_block_structural_actions_enabled(),
		'capabilities'                 => [
			'surfaces' => $surface_capabilities,
		],
		'canRecommendBlocks'           => $surface_capabilities['block']['available'],
		'canRecommendPatterns'         => $surface_capabilities['pattern']['available'],
		'canRecommendContent'          => $surface_capabilities['content']['available'],
		'canRecommendTemplates'        => $surface_capabilities['template']['available'],
		'canRecommendTemplateParts'    => $surface_capabilities['templatePart']['available'],
		'canRecommendNavigation'       => $surface_capabilities['navigation']['available'],
		'canRecommendGlobalStyles'     => $surface_capabilities['globalStyles']['available'],
		'canRecommendStyleBook'        => $surface_capabilities['styleBook']['available'],
		'templatePartAreas'            => FlavorAgent\Context\ServerCollector::for_template_part_areas(),
	];
}

/**
 * @return array<string, array<string, mixed>>
 */
function flavor_agent_get_editor_surface_capabilities(
	string $settings_url,
	string $connectors_url
): array {
	return FlavorAgent\Abilities\SurfaceCapabilities::build(
		$settings_url,
		$connectors_url
	);
}
