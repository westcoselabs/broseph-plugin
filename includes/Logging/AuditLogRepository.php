<?php
declare( strict_types=1 );

namespace Broseph\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditLogRepository {

	private \wpdb $db;
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'broseph_action_logs';
	}

	public function insert( array $data ): int|false {
		if ( empty( $data['status'] ) ) {
			return false;
		}
		$result = $this->db->insert( $this->table, $data );
		return false !== $result ? (int) $this->db->insert_id : false;
	}

	public function get_page( int $per_page = 25, int $page = 1 ): array {
		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, request_id, actor, task_type, method, endpoint, object_type, object_id, status, message, created_at FROM {$this->table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function count(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	public function count_recent_errors( int $days = 7 ): int {
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $this->db->get_var(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE (status LIKE %s OR status LIKE %s OR status LIKE %s) AND created_at >= %s",
				'%error%',
				'%reject%',
				'%fail%',
				$since
			)
		);
		return (int) $count;
	}
}
