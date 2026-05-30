<?php
declare( strict_types=1 );

namespace Broseph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	private const DB_VERSION = '1.0';

	public static function activate(): void {
		if ( ! get_option( 'broseph_site_id' ) ) {
			update_option( 'broseph_site_id', wp_generate_uuid4(), false );
		}

		if ( ! get_option( 'broseph_shared_secret' ) ) {
			update_option( 'broseph_shared_secret', self::generate_secret(), false );
		}

		$defaults = array(
			'broseph_allow_live_edits'              => '0',
			'broseph_allow_js_snippets'             => '0',
			'broseph_prefer_gitpress_landing_pages' => '1',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value, false );
			}
		}

		self::create_tables();
		update_option( 'broseph_db_version', self::DB_VERSION );
	}

	public static function create_tables(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'broseph_action_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id VARCHAR(64) DEFAULT NULL,
  actor VARCHAR(100) DEFAULT NULL,
  task_type VARCHAR(100) DEFAULT NULL,
  endpoint VARCHAR(255) DEFAULT NULL,
  method VARCHAR(10) DEFAULT NULL,
  payload_hash VARCHAR(64) DEFAULT NULL,
  object_type VARCHAR(100) DEFAULT NULL,
  object_id BIGINT(20) UNSIGNED DEFAULT NULL,
  status VARCHAR(50) NOT NULL,
  message TEXT DEFAULT NULL,
  before_snapshot LONGTEXT DEFAULT NULL,
  after_snapshot LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_status (status),
  KEY idx_created_at (created_at)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
