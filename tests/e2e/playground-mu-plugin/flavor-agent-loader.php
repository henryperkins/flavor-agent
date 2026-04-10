<?php
/**
 * Flavor Agent Playground loader.
 *
 * Loads the plugin in WordPress Playground without requiring standard
 * activation so browser smoke tests can run against a stable editor build.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function (): void {
		$stubbed_options = [
			'flavor_agent_azure_openai_endpoint'     => 'https://example.test/openai',
			'flavor_agent_azure_openai_key'          => 'playground-key',
			'flavor_agent_azure_embedding_deployment' => 'playground-embeddings',
			'flavor_agent_azure_chat_deployment'     => 'playground-chat',
			'flavor_agent_qdrant_url'                => 'https://example.test/qdrant',
			'flavor_agent_qdrant_key'                => 'playground-qdrant-key',
		];

		foreach ( $stubbed_options as $flavor_agent_option_name => $value ) {
			if ( get_option( $flavor_agent_option_name ) !== $value ) {
				update_option( $flavor_agent_option_name, $value );
			}
		}
	},
	0
);

$flavor_agent_plugin_main = WP_CONTENT_DIR . '/plugins/flavor-agent/flavor-agent.php';

if ( file_exists( $flavor_agent_plugin_main ) ) {
	require_once $flavor_agent_plugin_main;
}
