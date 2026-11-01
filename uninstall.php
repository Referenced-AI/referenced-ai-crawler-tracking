<?php
/**
 * Removes the plugin's table, options and scheduled event on uninstall.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$referenced_table = $wpdb->prefix . 'referenced_bot_hits';
$wpdb->query( "DROP TABLE IF EXISTS {$referenced_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table on uninstall; the name is prefix plus a constant and cannot be a placeholder.

foreach ( array( 'referenced_token', 'referenced_enabled', 'referenced_bots', 'referenced_last_send' ) as $referenced_option ) {
	delete_option( $referenced_option );
}

delete_metadata( 'user', 0, 'referenced_dismissed_connect_notice', '', true );

wp_clear_scheduled_hook( 'referenced_send_hits' );
