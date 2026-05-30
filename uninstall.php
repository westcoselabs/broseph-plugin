<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all options and custom tables created by Broseph.
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'broseph_action_logs' );

// Remove all options.
delete_option( 'broseph_site_id' );
delete_option( 'broseph_shared_secret' );
delete_option( 'broseph_allow_live_edits' );
delete_option( 'broseph_allow_js_snippets' );
delete_option( 'broseph_prefer_gitpress_landing_pages' );
delete_option( 'broseph_db_version' );

// Remove any lingering nonce transients (best-effort; transients with this prefix).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_broseph_nonce_%',
		'_transient_timeout_broseph_nonce_%'
	)
);
