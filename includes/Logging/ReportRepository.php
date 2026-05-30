<?php
declare( strict_types=1 );

namespace Broseph\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReportRepository {

	private \wpdb $db;
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'broseph_reports';
	}

	public function insert( array $data ): int|false {
		if ( empty( $data['report_type'] ) || empty( $data['status'] ) ) {
			return false;
		}
		$result = $this->db->insert( $this->table, $data );
		return false !== $result ? (int) $this->db->insert_id : false;
	}

	public function get_latest( int $limit = 10 ): array {
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, report_type, status, summary, created_at FROM {$this->table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function get_by_id( int $id ): ?array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->db->get_row(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
