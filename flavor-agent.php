<?php
/**
 * Plugin Name: Flavor Agent
 * Description: LLM-powered block recommendations in the native Inspector sidebar.
 * Version: 0.1.0
 * Author: Lakefront Digital
 * Text Domain: flavor-agent
 * Requires at least: 6.5
 * Requires PHP: 8.0
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

register_activation_hook( FLAVOR_AGENT_FILE, [ FlavorAgent\Patterns\PatternIndex::class, 'activate' ] );
register_deactivation_hook( FLAVOR_AGENT_FILE, [ FlavorAgent\Patterns\PatternIndex::class, 'deactivate' ] );

add_action( 'enqueue_block_editor_assets', 'flavor_agent_enqueue_editor' );
add_action( 'rest_api_init', [ FlavorAgent\REST\Agent_Controller::class, 'register_routes' ] );
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
	'flavor_agent_azure_openai_endpoint',
	'flavor_agent_azure_openai_key',
	'flavor_agent_azure_embedding_deployment',
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

// Recommended pattern category for AI-ranked patterns in the inserter.
add_action( 'init', function () {
    if ( function_exists( 'register_block_pattern_category' ) ) {
        register_block_pattern_category( 'recommended', [
            'label' => __( 'Recommended', 'flavor-agent' ),
        ] );
    }
} );

add_filter( 'block_editor_settings_all', function ( $settings ) {
    $cats        = $settings['__experimentalBlockPatternCategories'] ?? [];
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
        $settings['__experimentalBlockPatternCategories'] = array_merge( [ $recommended ], $rest );
    }

    return $settings;
} );

function flavor_agent_enqueue_editor(): void {
    $asset_path = FLAVOR_AGENT_DIR . 'build/index.asset.php';
    if ( ! file_exists( $asset_path ) ) {
        return;
    }

    $asset = include $asset_path;
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

    wp_localize_script( 'flavor-agent-editor', 'flavorAgentData', [
        'restUrl'              => rest_url( 'flavor-agent/v1/' ),
        'nonce'                => wp_create_nonce( 'wp_rest' ),
        'hasApiKey'            => (bool) get_option( 'flavor_agent_api_key' ),
        'canRecommendPatterns' => (bool) (
            get_option( 'flavor_agent_azure_openai_endpoint' )
            && get_option( 'flavor_agent_azure_openai_key' )
            && get_option( 'flavor_agent_azure_embedding_deployment' )
            && get_option( 'flavor_agent_azure_chat_deployment' )
            && get_option( 'flavor_agent_qdrant_url' )
            && get_option( 'flavor_agent_qdrant_key' )
        ),
        'canRecommendTemplates' => (bool) (
            get_option( 'flavor_agent_azure_openai_endpoint' )
            && get_option( 'flavor_agent_azure_openai_key' )
            && get_option( 'flavor_agent_azure_chat_deployment' )
        ),
        'templatePartAreas'    => FlavorAgent\Context\ServerCollector::for_template_part_areas(),
    ] );
}
