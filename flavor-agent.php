<?php
/**
 * Plugin Name: Flavor Agent
 * Description: LLM-powered block recommendations in the native Inspector sidebar.
 * Version: 0.1.0
 * Author: Lakefront Digital
 * Text Domain: flavor-agent
 * Requires at least: 7.0
 * Requires PHP: 8.0
 */

declare(strict_types=1);

use FlavorAgent\OpenAI\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FLAVOR_AGENT_VERSION', '0.1.0' );
define( 'FLAVOR_AGENT_FILE', __FILE__ );
define( 'FLAVOR_AGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLAVOR_AGENT_URL', plugin_dir_url( __FILE__ ) );

require_once FLAVOR_AGENT_DIR . 'vendor/autoload.php';

register_activation_hook(
	FLAVOR_AGENT_FILE,
	function () {
		FlavorAgent\Activity\Repository::install();
		FlavorAgent\Activity\Repository::ensure_prune_schedule();
		FlavorAgent\Patterns\PatternIndex::activate();
		FlavorAgent\Cloudflare\AISearchClient::schedule_prewarm();
	}
);
register_deactivation_hook(
	FLAVOR_AGENT_FILE,
	function () {
		FlavorAgent\Patterns\PatternIndex::deactivate();
		wp_clear_scheduled_hook( FlavorAgent\Activity\Repository::PRUNE_CRON_HOOK );
		wp_clear_scheduled_hook( FlavorAgent\Cloudflare\AISearchClient::PREWARM_CRON_HOOK );
		wp_clear_scheduled_hook( FlavorAgent\Cloudflare\AISearchClient::CONTEXT_WARM_CRON_HOOK );
	}
);

add_action( 'enqueue_block_editor_assets', 'flavor_agent_enqueue_editor' );
add_action( 'init', [ FlavorAgent\Activity\Repository::class, 'maybe_install' ], 5 );
add_action( 'init', [ FlavorAgent\Activity\Repository::class, 'ensure_prune_schedule' ], 6 );
add_action( 'rest_api_init', [ FlavorAgent\REST\Agent_Controller::class, 'register_routes' ] );
add_action( 'admin_menu', [ FlavorAgent\Admin\ActivityPage::class, 'add_menu' ] );
add_action( 'admin_menu', [ FlavorAgent\Settings::class, 'add_menu' ] );
add_action( 'admin_init', [ FlavorAgent\Settings::class, 'register_settings' ] );
add_action( 'wp_abilities_api_categories_init', [ FlavorAgent\Abilities\Registration::class, 'register_category' ] );
add_action( 'wp_abilities_api_init', [ FlavorAgent\Abilities\Registration::class, 'register_abilities' ] );

// Pattern index lifecycle hooks.
add_action( FlavorAgent\Patterns\PatternIndex::CRON_HOOK, [ FlavorAgent\Patterns\PatternIndex::class, 'sync' ] );
add_action( 'after_switch_theme', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );
add_action( 'activated_plugin', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );
add_action( 'deactivated_plugin', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );
add_action( 'upgrader_process_complete', [ FlavorAgent\Patterns\PatternIndex::class, 'handle_registry_change' ] );

foreach ( [
	'flavor_agent_openai_provider',
	'flavor_agent_azure_openai_endpoint',
	'flavor_agent_azure_openai_key',
	'flavor_agent_azure_embedding_deployment',
	'flavor_agent_azure_chat_deployment',
	'flavor_agent_openai_native_api_key',
	'flavor_agent_openai_native_embedding_model',
	'flavor_agent_openai_native_chat_model',
	'connectors_ai_openai_api_key',
	'flavor_agent_qdrant_url',
	'flavor_agent_qdrant_key',
	'home',
] as $option_name ) {
	add_action(
		"update_option_{$option_name}",
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
	FlavorAgent\Activity\Repository::PRUNE_CRON_HOOK,
	[ FlavorAgent\Activity\Repository::class, 'prune' ]
);

// Recommended pattern category for AI-ranked patterns in the inserter.
add_action(
	'init',
	function () {
		register_block_pattern_category(
			'recommended',
			[
				'label' => __( 'Recommended', 'flavor-agent' ),
			]
		);
	}
);

add_filter(
	'block_editor_settings_all',
	function ( $settings ) {
		// Prefer stable key when core promotes it; fall back to experimental.
		$cats_key = isset( $settings['blockPatternCategories'] )
			? 'blockPatternCategories'
			: '__experimentalBlockPatternCategories';

		$cats        = $settings[ $cats_key ] ?? [];
		$recommended = null;
		$rest        = [];

		foreach ( $cats as $cat ) {
			if ( ( $cat['name'] ?? '' ) === 'recommended' ) {
				$recommended = $cat;
			} else {
				$rest[] = $cat;
			}
		}

		if ( $recommended ) {
			$settings[ $cats_key ] = array_merge( [ $recommended ], $rest );
		}

		return $settings;
	}
);

function flavor_agent_enqueue_editor(): void {
	$asset_path = FLAVOR_AGENT_DIR . 'build/index.asset.php';
	if ( ! file_exists( $asset_path ) ) {
		return;
	}

	$asset    = include $asset_path;
	$css_path = FLAVOR_AGENT_DIR . 'build/index.css';

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

	$settings_url         = admin_url( 'options-general.php?page=flavor-agent' );
	$connectors_url       = admin_url( 'options-connectors.php' );
	$surface_capabilities = flavor_agent_get_editor_surface_capabilities(
		$settings_url,
		$connectors_url
	);
	$can_manage_settings  = current_user_can( 'manage_options' );

	wp_localize_script(
		'flavor-agent-editor',
		'flavorAgentData',
		[
			'restUrl'                      => rest_url( 'flavor-agent/v1/' ),
			'nonce'                        => wp_create_nonce( 'wp_rest' ),
			'settingsUrl'                  => $settings_url,
			'connectorsUrl'                => $connectors_url,
			'canManageFlavorAgentSettings' => $can_manage_settings,
			'capabilities'                 => [
				'surfaces' => $surface_capabilities,
			],
			'canRecommendBlocks'           => $surface_capabilities['block']['available'],
			'canRecommendPatterns'         => $surface_capabilities['pattern']['available'],
			'canRecommendTemplates'        => $surface_capabilities['template']['available'],
			'canRecommendTemplateParts'    => $surface_capabilities['templatePart']['available'],
			'canRecommendNavigation'       => $surface_capabilities['navigation']['available'],
			'templatePartAreas'            => FlavorAgent\Context\ServerCollector::for_template_part_areas(),
		]
	);
}

/**
 * @return array<string, array<string, mixed>>
 */
function flavor_agent_get_editor_surface_capabilities(
	string $settings_url,
	string $connectors_url
): array {
	$block_available       = FlavorAgent\LLM\ChatClient::is_supported();
	$chat_available        = Provider::chat_configured();
	$pattern_available     = (bool) (
		Provider::embedding_configured()
		&& $chat_available
		&& get_option( 'flavor_agent_qdrant_url' )
		&& get_option( 'flavor_agent_qdrant_key' )
	);
	$can_edit_theme        = current_user_can( 'edit_theme_options' );
	$can_manage_settings   = current_user_can( 'manage_options' );
	$block_message         = __(
		$can_manage_settings
			? 'Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent, or configure a text-generation provider in Settings > Connectors, to enable block recommendations.'
			: 'Block recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
		'flavor-agent'
	);
	$template_message      = __(
		$can_manage_settings
			? 'Template recommendations rely on Flavor Agent\'s configured chat provider. Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent.'
			: 'Template recommendations are not configured yet. Ask an administrator to configure Flavor Agent for this site.',
		'flavor-agent'
	);
	$template_part_message = __(
		$can_manage_settings
			? 'Template-part recommendations rely on Flavor Agent\'s configured chat provider. Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent.'
			: 'Template-part recommendations are not configured yet. Ask an administrator to configure Flavor Agent for this site.',
		'flavor-agent'
	);
	$navigation_message    = __(
		$can_manage_settings
			? 'Navigation recommendations rely on Flavor Agent\'s configured chat provider. Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent.'
			: 'Navigation recommendations are not configured yet. Ask an administrator to configure Flavor Agent for this site.',
		'flavor-agent'
	);

	return [
		'block'        => [
			'available' => $block_available,
			'reason'    => $block_available ? 'ready' : 'block_backend_unconfigured',
			'owner'     => 'plugin_or_core',
			'actions'   => $can_manage_settings
				? [
					[
						'label' => 'Settings > Flavor Agent',
						'href'  => $settings_url,
					],
					[
						'label' => 'Settings > Connectors',
						'href'  => $connectors_url,
					],
				]
				: [],
			'message'   => $block_message,
		],
		'pattern'      => [
			'available'          => $pattern_available,
			'reason'             => $pattern_available ? 'ready' : 'pattern_backend_unconfigured',
			'owner'              => 'plugin_settings',
			'configurationLabel' => $can_manage_settings ? 'Settings > Flavor Agent' : '',
			'configurationUrl'   => $can_manage_settings ? $settings_url : '',
			'message'            => __(
				$can_manage_settings
					? 'Pattern recommendations rely on Flavor Agent\'s chat and embedding backends plus Qdrant in Settings > Flavor Agent.'
					: 'Pattern recommendations are not configured yet. Ask an administrator to configure Flavor Agent for this site.',
				'flavor-agent'
			),
		],
		'template'     => [
			'available'          => $chat_available,
			'reason'             => $chat_available ? 'ready' : 'plugin_provider_unconfigured',
			'owner'              => 'plugin_settings',
			'configurationLabel' => $can_manage_settings ? 'Settings > Flavor Agent' : '',
			'configurationUrl'   => $can_manage_settings ? $settings_url : '',
			'message'            => $template_message,
		],
		'templatePart' => [
			'available'          => $chat_available,
			'reason'             => $chat_available ? 'ready' : 'plugin_provider_unconfigured',
			'owner'              => 'plugin_settings',
			'configurationLabel' => $can_manage_settings ? 'Settings > Flavor Agent' : '',
			'configurationUrl'   => $can_manage_settings ? $settings_url : '',
			'message'            => $template_part_message,
		],
		'navigation'   => [
			'available'          => $chat_available && $can_edit_theme,
			'reason'             => ! $can_edit_theme
				? 'missing_theme_capability'
				: ( $chat_available ? 'ready' : 'plugin_provider_unconfigured' ),
			'owner'              => 'plugin_settings',
			'configurationLabel' => ( $can_edit_theme && $can_manage_settings ) ? 'Settings > Flavor Agent' : '',
			'configurationUrl'   => ( $can_edit_theme && $can_manage_settings ) ? $settings_url : '',
			'actions'            => ( $can_edit_theme && $can_manage_settings )
				? [
					[
						'label' => 'Settings > Flavor Agent',
						'href'  => $settings_url,
					],
				]
				: [],
			'advisoryOnly'       => true,
			'message'            => ! $can_edit_theme
				? __(
					'Navigation recommendations require the edit_theme_options capability.',
					'flavor-agent'
				)
				: $navigation_message,
		],
	];
}
