<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailLogService {

	public function get_recent_logs( int $limit = 20 ): array {
		$limit = min( max( 1, $limit ), 100 );

		// Try each known log adapter in preference order.
		foreach ( array(
			array( $this, 'try_wpsmtp_logs' ),
			array( $this, 'try_wpml_logs' ),
			array( $this, 'try_fluentsmtp_logs' ),
		) as $adapter ) {
			$result = $adapter( $limit );
			if ( null !== $result ) {
				return $result;
			}
		}

		return array(
			'supported'     => false,
			'source_plugin' => null,
			'message'       => 'No supported mail logging plugin detected. Supported: WP Mail SMTP (Pro), WP Mail Logging, FluentSMTP.',
			'logs'          => array(),
		);
	}

	// ── Plugin adapters ───────────────────────────────────────────────────────

	private function try_wpsmtp_logs( int $limit ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'wp_mail_smtp_logs';

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `date_sent`, `to`, `subject`, `status` FROM `{$table}` ORDER BY `date_sent` DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return null;
		}

		return array(
			'supported'     => true,
			'source_plugin' => 'WP Mail SMTP',
			'logs'          => array_map( array( $this, 'format_wpsmtp_row' ), $rows ),
		);
	}

	private function try_wpml_logs( int $limit ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpml_mails';

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `timestamp`, `to`, `subject` FROM `{$table}` ORDER BY `timestamp` DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return null;
		}

		return array(
			'supported'     => true,
			'source_plugin' => 'WP Mail Logging',
			'logs'          => array_map( array( $this, 'format_wpml_row' ), $rows ),
		);
	}

	private function try_fluentsmtp_logs( int $limit ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'fluentmail_logs';

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `created_at`, `to`, `subject`, `status` FROM `{$table}` ORDER BY `created_at` DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return null;
		}

		return array(
			'supported'     => true,
			'source_plugin' => 'FluentSMTP',
			'logs'          => array_map( array( $this, 'format_fluent_row' ), $rows ),
		);
	}

	// ── Row formatters ────────────────────────────────────────────────────────

	private function format_wpsmtp_row( array $row ): array {
		return array(
			'timestamp'     => $row['date_sent'] ?? null,
			'to_domain'     => $this->mask_email( (string) ( $row['to'] ?? '' ) ),
			'subject'       => $row['subject'] ?? null,
			'status'        => $row['status'] ?? null,
			'source_plugin' => 'WP Mail SMTP',
		);
	}

	private function format_wpml_row( array $row ): array {
		return array(
			'timestamp'     => $row['timestamp'] ?? null,
			'to_domain'     => $this->mask_email( (string) ( $row['to'] ?? '' ) ),
			'subject'       => $row['subject'] ?? null,
			'status'        => 'logged',
			'source_plugin' => 'WP Mail Logging',
		);
	}

	private function format_fluent_row( array $row ): array {
		$to_raw = (string) ( $row['to'] ?? '' );

		// FluentSMTP serialises recipients as a JSON array in some versions.
		$decoded = json_decode( $to_raw, true );
		if ( is_array( $decoded ) ) {
			$to_raw = implode( ', ', $decoded );
		}

		return array(
			'timestamp'     => $row['created_at'] ?? null,
			'to_domain'     => $this->mask_email( $to_raw ),
			'subject'       => $row['subject'] ?? null,
			'status'        => $row['status'] ?? null,
			'source_plugin' => 'FluentSMTP',
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $result === $table;
	}

	/**
	 * Returns only the domain portion of the first recipient address.
	 * E.g. "John <john@example.com>, bob@other.com" → "@example.com"
	 */
	private function mask_email( string $raw ): string {
		$first = trim( explode( ',', $raw )[0] );
		$first = trim( $first, '<> "\'' );
		$parts = explode( '@', $first, 2 );
		return count( $parts ) === 2 ? ( '@' . $parts[1] ) : '***';
	}
}
