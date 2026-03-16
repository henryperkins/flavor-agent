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

add_action( 'enqueue_block_editor_assets', 'flavor_agent_enqueue_editor' );
add_action( 'rest_api_init', [ FlavorAgent\REST\Agent_Controller::class, 'register_routes' ] );
add_action( 'admin_menu', [ FlavorAgent\Settings::class, 'add_menu' ] );
add_action( 'admin_init', [ FlavorAgent\Settings::class, 'register_settings' ] );
add_action( 'wp_abilities_api_categories_init', [ FlavorAgent\Abilities\Registration::class, 'register_category' ] );
add_action( 'wp_abilities_api_init', [ FlavorAgent\Abilities\Registration::class, 'register_abilities' ] );

function flavor_agent_enqueue_editor(): void {
    $asset_path = FLAVOR_AGENT_DIR . 'build/index.asset.php';
    if ( ! file_exists( $asset_path ) ) {
        return;
    }

    $asset = include $asset_path;

    wp_enqueue_script(
        'flavor-agent-editor',
        FLAVOR_AGENT_URL . 'build/index.js',
        $asset['dependencies'],
        $asset['version'],
        true
    );

    wp_localize_script( 'flavor-agent-editor', 'flavorAgentData', [
        'restUrl'    => rest_url( 'flavor-agent/v1/' ),
        'nonce'      => wp_create_nonce( 'wp_rest' ),
        'hasApiKey'  => (bool) get_option( 'flavor_agent_api_key' ),
    ] );
}
