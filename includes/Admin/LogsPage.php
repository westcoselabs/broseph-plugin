<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogsPage {

	private const PAGE_SLUG = 'broseph-logs';
	private const PER_PAGE  = 30;

	private \Broseph\Logging\AuditLogRepository $repository;

	public function __construct( \Broseph\Logging\AuditLogRepository $repository ) {
		$this->repository = $repository;
	}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		add_management_page(
			__( 'Broseph Logs', 'broseph' ),
			__( 'Broseph Logs', 'broseph' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'broseph-admin',
			BROSEPH_PLUGIN_URL . 'assets/admin.css',
			array(),
			BROSEPH_VERSION
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$total        = $this->repository->count();
		$rows         = $this->repository->get_page( self::PER_PAGE, $current_page );
		$total_pages  = $total > 0 ? (int) ceil( $total / self::PER_PAGE ) : 1;
		?>
		<div class="wrap broseph-wrap">
			<h1><?php esc_html_e( 'Broseph Action Logs', 'broseph' ); ?></h1>

			<p class="description">
				<?php
				printf(
					/* translators: %d: total log entries */
					esc_html__( '%d total log entries.', 'broseph' ),
					(int) $total
				);
				?>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No log entries found.', 'broseph' ); ?></p>
			<?php else : ?>
				<table class="widefat striped broseph-logs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date (UTC)', 'broseph' ); ?></th>
							<th><?php esc_html_e( 'Status', 'broseph' ); ?></th>
							<th><?php esc_html_e( 'Task Type', 'broseph' ); ?></th>
							<th><?php esc_html_e( 'Method', 'broseph' ); ?></th>
							<th><?php esc_html_e( 'Endpoint', 'broseph' ); ?></th>
							<th><?php esc_html_e( 'Object', 'broseph' ); ?></th>
							<th><?php esc_html_e( 'Message', 'broseph' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td class="broseph-td-date">
									<?php echo esc_html( $row['created_at'] ?? '—' ); ?>
								</td>
								<td>
									<?php echo $this->status_badge( (string) ( $row['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</td>
								<td><?php echo esc_html( $row['task_type'] ?? '—' ); ?></td>
								<td class="broseph-td-method">
									<?php echo esc_html( $row['method'] ?? '—' ); ?>
								</td>
								<td class="broseph-td-endpoint">
									<?php echo esc_html( $row['endpoint'] ?? '—' ); ?>
								</td>
								<td>
									<?php
									$obj  = $row['object_type'] ?? '';
									$oid  = $row['object_id'] ?? '';
									$cell = '';
									if ( $obj && $oid ) {
										$cell = $obj . ' #' . $oid;
									} elseif ( $obj ) {
										$cell = $obj;
									}
									echo esc_html( $cell ?: '—' );
									?>
								</td>
								<td class="broseph-td-message">
									<?php echo esc_html( $row['message'] ?? '—' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="broseph-pagination">
					<?php
					echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'total'   => $total_pages,
							'current' => $current_page,
							'type'    => 'plain',
						)
					);
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function status_badge( string $status ): string {
		$lower = strtolower( $status );
		if ( str_contains( $lower, 'success' ) || 'ok' === $lower || 'accepted' === $lower ) {
			$class = 'broseph-badge-success';
		} elseif ( str_contains( $lower, 'error' ) || str_contains( $lower, 'fail' ) || str_contains( $lower, 'reject' ) ) {
			$class = 'broseph-badge-error';
		} elseif ( str_contains( $lower, 'pending' ) || str_contains( $lower, 'running' ) ) {
			$class = 'broseph-badge-pending';
		} else {
			$class = 'broseph-badge-default';
		}
		return sprintf(
			'<span class="broseph-badge %s">%s</span>',
			esc_attr( $class ),
			esc_html( $status )
		);
	}
}
