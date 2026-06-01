<?php
declare( strict_types=1 );

namespace Broseph\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardPage {

	private \Broseph\Logging\AuditLogRepository $log_repo;
	private \Broseph\Logging\ReportRepository   $report_repo;

	public function __construct(
		\Broseph\Logging\AuditLogRepository $log_repo,
		\Broseph\Logging\ReportRepository $report_repo
	) {
		$this->log_repo    = $log_repo;
		$this->report_repo = $report_repo;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$site_id      = (string) get_option( 'broseph_site_id', '' );
		$raw_secret   = (string) get_option( 'broseph_shared_secret', '' );
		$masked       = $raw_secret ? str_repeat( '•', 40 ) . substr( $raw_secret, -6 ) : '—';

		$theme          = wp_get_theme();
		$divi_active    = defined( 'ET_CORE_VERSION' ) || class_exists( 'ET_Builder_Plugin' );
		$gitpress_active = class_exists( 'GitPress' ) || defined( 'GITPRESS_VERSION' );

		$live_edits  = (bool) get_option( 'broseph_allow_live_edits', false );
		$js_snippets = (bool) get_option( 'broseph_allow_js_snippets', false );
		$prefer_gp   = (bool) get_option( 'broseph_prefer_gitpress_landing_pages', true );

		$recent_logs   = $this->log_repo->get_page( 5, 1 );
		$latest_reports = $this->report_repo->get_latest( 1 );
		$latest_report  = ! empty( $latest_reports ) ? $latest_reports[0] : null;
		?>
		<div class="wrap broseph-wrap">
			<h1 class="broseph-page-title">
				<span class="dashicons dashicons-superhero"></span>
				<?php esc_html_e( 'Broseph', 'broseph' ); ?>
			</h1>

			<div class="broseph-dashboard-grid">

				<div class="broseph-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Connection Status', 'broseph' ); ?></h2>
					<dl class="broseph-dl">
						<dt><?php esc_html_e( 'Site ID', 'broseph' ); ?></dt>
						<dd><code><?php echo $site_id ? esc_html( $site_id ) : '—'; ?></code></dd>
						<dt><?php esc_html_e( 'Shared Secret', 'broseph' ); ?></dt>
						<dd><code class="broseph-secret-masked"><?php echo esc_html( $masked ); ?></code></dd>
						<dt><?php esc_html_e( 'Open Claw', 'broseph' ); ?></dt>
						<dd><span class="broseph-badge broseph-badge-default"><?php esc_html_e( 'Not connected', 'broseph' ); ?></span></dd>
						<dt><?php esc_html_e( 'Last Seen', 'broseph' ); ?></dt>
						<dd class="broseph-muted"><?php esc_html_e( '—', 'broseph' ); ?></dd>
					</dl>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-connection' ) ); ?>" class="button button-secondary broseph-card-action">
						<?php esc_html_e( 'Manage Connection', 'broseph' ); ?>
					</a>
				</div>

				<div class="broseph-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Site Snapshot', 'broseph' ); ?></h2>
					<dl class="broseph-dl">
						<dt><?php esc_html_e( 'Site URL', 'broseph' ); ?></dt>
						<dd><?php echo esc_html( site_url() ); ?></dd>
						<dt><?php esc_html_e( 'WordPress', 'broseph' ); ?></dt>
						<dd><?php echo esc_html( get_bloginfo( 'version' ) ); ?></dd>
						<dt><?php esc_html_e( 'PHP', 'broseph' ); ?></dt>
						<dd><?php echo esc_html( PHP_VERSION ); ?></dd>
						<dt><?php esc_html_e( 'Active Theme', 'broseph' ); ?></dt>
						<dd><?php echo esc_html( $theme->get( 'Name' ) ); ?></dd>
						<dt><?php esc_html_e( 'Divi', 'broseph' ); ?></dt>
						<dd><?php echo $divi_active ? '<span class="broseph-badge broseph-badge-success">Active</span>' : '<span class="broseph-badge broseph-badge-default">Inactive</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
						<dt><?php esc_html_e( 'GitPress', 'broseph' ); ?></dt>
						<dd><?php echo $gitpress_active ? '<span class="broseph-badge broseph-badge-success">Active</span>' : '<span class="broseph-badge broseph-badge-default">Inactive</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
					</dl>
				</div>

				<div class="broseph-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Safety Settings', 'broseph' ); ?></h2>
					<dl class="broseph-dl">
						<dt><?php esc_html_e( 'Live Edits', 'broseph' ); ?></dt>
						<dd><?php echo $live_edits ? '<span class="broseph-badge broseph-badge-success">Enabled</span>' : '<span class="broseph-badge broseph-badge-error">Disabled</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
						<dt><?php esc_html_e( 'JS Snippets', 'broseph' ); ?></dt>
						<dd><?php echo $js_snippets ? '<span class="broseph-badge broseph-badge-success">Enabled</span>' : '<span class="broseph-badge broseph-badge-error">Disabled</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
						<dt><?php esc_html_e( 'Prefer GitPress', 'broseph' ); ?></dt>
						<dd><?php echo $prefer_gp ? '<span class="broseph-badge broseph-badge-success">Enabled</span>' : '<span class="broseph-badge broseph-badge-default">Disabled</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
					</dl>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-settings' ) ); ?>" class="button button-secondary broseph-card-action">
						<?php esc_html_e( 'Edit Settings', 'broseph' ); ?>
					</a>
				</div>

				<div class="broseph-card broseph-card-wide">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Recent Activity', 'broseph' ); ?></h2>
					<?php if ( empty( $recent_logs ) ) : ?>
						<p class="broseph-muted"><?php esc_html_e( 'No activity logged yet.', 'broseph' ); ?></p>
					<?php else : ?>
						<table class="widefat broseph-mini-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date (UTC)', 'broseph' ); ?></th>
									<th><?php esc_html_e( 'Status', 'broseph' ); ?></th>
									<th><?php esc_html_e( 'Task', 'broseph' ); ?></th>
									<th><?php esc_html_e( 'Message', 'broseph' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent_logs as $row ) : ?>
									<tr>
										<td class="broseph-td-date"><?php echo esc_html( $row['created_at'] ?? '—' ); ?></td>
										<td><?php echo $this->log_status_badge( (string) ( $row['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										<td><?php echo esc_html( $row['task_type'] ?? '—' ); ?></td>
										<td class="broseph-td-message"><?php echo esc_html( $row['message'] ?? '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-logs' ) ); ?>" class="broseph-card-link">
						<?php esc_html_e( 'View all logs →', 'broseph' ); ?>
					</a>
				</div>

				<div class="broseph-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Latest Report', 'broseph' ); ?></h2>
					<?php if ( null === $latest_report ) : ?>
						<p class="broseph-muted"><?php esc_html_e( 'No reports generated yet.', 'broseph' ); ?></p>
					<?php else : ?>
						<dl class="broseph-dl">
							<dt><?php esc_html_e( 'Status', 'broseph' ); ?></dt>
							<dd><?php echo $this->report_status_badge( (string) ( $latest_report['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
							<dt><?php esc_html_e( 'Type', 'broseph' ); ?></dt>
							<dd><?php echo esc_html( $latest_report['report_type'] ?? '—' ); ?></dd>
							<dt><?php esc_html_e( 'Generated', 'broseph' ); ?></dt>
							<dd class="broseph-muted"><?php echo esc_html( $latest_report['created_at'] ?? '—' ); ?></dd>
							<dt><?php esc_html_e( 'Summary', 'broseph' ); ?></dt>
							<dd><?php echo esc_html( $latest_report['summary'] ?? '—' ); ?></dd>
						</dl>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-reports' ) ); ?>" class="broseph-card-link">
						<?php esc_html_e( 'View all reports →', 'broseph' ); ?>
					</a>
				</div>

				<div class="broseph-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Quick Links', 'broseph' ); ?></h2>
					<ul class="broseph-quick-links">
						<li>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-connection' ) ); ?>">
								<span class="dashicons dashicons-admin-network"></span>
								<?php esc_html_e( 'Connection', 'broseph' ); ?>
							</a>
						</li>
						<li>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-reports' ) ); ?>">
								<span class="dashicons dashicons-chart-bar"></span>
								<?php esc_html_e( 'Reports', 'broseph' ); ?>
							</a>
						</li>
						<li>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-logs' ) ); ?>">
								<span class="dashicons dashicons-list-view"></span>
								<?php esc_html_e( 'Logs', 'broseph' ); ?>
							</a>
						</li>
						<li>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=broseph-settings' ) ); ?>">
								<span class="dashicons dashicons-admin-settings"></span>
								<?php esc_html_e( 'Settings', 'broseph' ); ?>
							</a>
						</li>
					</ul>
				</div>

			</div>
		</div>
		<?php
	}

	private function log_status_badge( string $status ): string {
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

	private function report_status_badge( string $status ): string {
		$lower = strtolower( $status );
		if ( 'healthy' === $lower || str_contains( $lower, 'ok' ) ) {
			$class = 'broseph-badge-success';
		} elseif ( 'critical' === $lower || str_contains( $lower, 'error' ) ) {
			$class = 'broseph-badge-error';
		} elseif ( 'needs_review' === $lower || str_contains( $lower, 'warning' ) ) {
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
