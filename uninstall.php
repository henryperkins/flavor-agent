<?php

/**
 * Flavor Agent uninstall handler.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/UninstallOptions.php';

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
	$flavor_agent_table_name = $wpdb->prefix . 'flavor_agent_activity';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned activity table is intentionally removed during uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$flavor_agent_table_name}" );
}

foreach ( \FlavorAgent\UninstallOptions::names() as $flavor_agent_option_name ) {
	delete_option( $flavor_agent_option_name );
}
