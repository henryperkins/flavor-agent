<?php
/**
 * Flavor Agent uninstall handler.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'flavor_agent_reindex_patterns' );
wp_clear_scheduled_hook( 'flavor_agent_prewarm_docs' );
wp_clear_scheduled_hook( 'flavor_agent_warm_docs_context' );
delete_transient( 'flavor_agent_sync_lock' );

foreach ( [
	'flavor_agent_api_key',
	'flavor_agent_model',
	'flavor_agent_azure_openai_endpoint',
	'flavor_agent_azure_openai_key',
	'flavor_agent_azure_embedding_deployment',
	'flavor_agent_azure_chat_deployment',
	'flavor_agent_qdrant_url',
	'flavor_agent_qdrant_key',
	'flavor_agent_cloudflare_ai_search_account_id',
	'flavor_agent_cloudflare_ai_search_instance_id',
	'flavor_agent_cloudflare_ai_search_api_token',
	'flavor_agent_cloudflare_ai_search_max_results',
	'flavor_agent_pattern_index_state',
	'flavor_agent_docs_prewarm_state',
	'flavor_agent_docs_warm_queue',
] as $option_name ) {
	delete_option( $option_name );
}
