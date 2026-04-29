<?php
/**
 * Flavor Agent uninstall handler.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'flavor_agent_reindex_patterns' );
wp_clear_scheduled_hook( 'flavor_agent_prune_activity' );
wp_clear_scheduled_hook( 'flavor_agent_backfill_activity_admin_projection' );
wp_clear_scheduled_hook( 'flavor_agent_prewarm_docs' );
wp_clear_scheduled_hook( 'flavor_agent_warm_docs_context' );
wp_clear_scheduled_hook( 'flavor_agent_warm_core_roadmap_guidance' );
delete_transient( 'flavor_agent_sync_lock' );
delete_transient( 'flavor_agent_core_roadmap_guidance_v1' );
delete_transient( 'flavor_agent_core_roadmap_guidance_schedule_lock' );

global $wpdb;

if ( is_object( $wpdb ) && isset( $wpdb->prefix ) ) {
	$table_name = $wpdb->prefix . 'flavor_agent_activity';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned activity table is intentionally removed during uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
}

foreach ( [
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
] as $flavor_agent_option_name ) {
	delete_option( $flavor_agent_option_name );
}
