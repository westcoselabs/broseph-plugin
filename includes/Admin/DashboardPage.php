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

		$site_id         = (string) get_option( 'broseph_site_id', '' );
		$has_secret      = '' !== (string) get_option( 'broseph_shared_secret', '' );
		$theme           = wp_get_theme();
		$divi_active     = defined( 'ET_CORE_VERSION' ) || class_exists( 'ET_Builder_Plugin' );
		$gitpress_active = class_exists( 'GitPress' ) || defined( 'GITPRESS_VERSION' );
		$live_edits      = (bool) get_option( 'broseph_allow_live_edits', false );
		$js_snippets     = (bool) get_option( 'broseph_allow_js_snippets', false );
		$prefer_gp       = (bool) get_option( 'broseph_prefer_gitpress_landing_pages', true );
		$recent_logs     = $this->log_repo->get_page( 5, 1 );
		$latest_reports  = $this->report_repo->get_latest( 1 );
		$latest_report   = ! empty( $latest_reports ) ? $latest_reports[0] : null;

		$conn_url     = admin_url( 'admin.php?page=broseph-connection' );
		$reports_url  = admin_url( 'admin.php?page=broseph-reports' );
		$logs_url     = admin_url( 'admin.php?page=broseph-logs' );
		$settings_url = admin_url( 'admin.php?page=broseph-settings' );
		?>
		<div class="wrap broseph-wrap">

			<!-- ── Header ────────────────────────────────────────────── -->
			<div class="broseph-db-header">
				<div class="broseph-db-header-info">
					<h1 class="broseph-db-title">
						<span class="dashicons dashicons-superhero" aria-hidden="true"></span>
						<?php esc_html_e( 'Broseph', 'broseph' ); ?>
					</h1>
					<p class="broseph-db-subtitle"><?php esc_html_e( 'AI WordPress control hub for Open Claw', 'broseph' ); ?></p>
				</div>
				<div class="broseph-db-header-actions">
					<a href="<?php echo esc_url( $conn_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Manage Connection', 'broseph' ); ?>
					</a>
				</div>
			</div>

			<!-- ── Status strip ──────────────────────────────────────── -->
			<div class="broseph-status-strip" role="list" aria-label="<?php esc_attr_e( 'System status', 'broseph' ); ?>">
				<div class="broseph-status-item" role="listitem">
					<span class="broseph-status-label"><?php esc_html_e( 'Open Claw', 'broseph' ); ?></span>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->badge( 'default', __( 'Not Connected', 'broseph' ) ); ?>
				</div>
				<div class="broseph-status-item" role="listitem">
					<span class="broseph-status-label"><?php esc_html_e( 'Divi', 'broseph' ); ?></span>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $divi_active ? $this->badge( 'success', __( 'Active', 'broseph' ) ) : $this->badge( 'default', __( 'Inactive', 'broseph' ) ); ?>
				</div>
				<div class="broseph-status-item" role="listitem">
					<span class="broseph-status-label"><?php esc_html_e( 'GitPress', 'broseph' ); ?></span>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $gitpress_active ? $this->badge( 'success', __( 'Active', 'broseph' ) ) : $this->badge( 'default', __( 'Inactive', 'broseph' ) ); ?>
				</div>
				<div class="broseph-status-item" role="listitem">
					<span class="broseph-status-label"><?php esc_html_e( 'Live Edits', 'broseph' ); ?></span>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $live_edits ? $this->badge( 'pending', __( 'Enabled', 'broseph' ) ) : $this->badge( 'success', __( 'Disabled', 'broseph' ) ); ?>
				</div>
			</div>

			<!-- ── Dashboard grid ────────────────────────────────────── -->
			<div class="broseph-dashboard-grid">

				<!-- Connection -->
				<div class="broseph-card broseph-db-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Connection', 'broseph' ); ?></h2>
					<dl class="broseph-dl">
						<dt><?php esc_html_e( 'Site ID', 'broseph' ); ?></dt>
						<dd>
							<?php if ( $site_id ) : ?>
								<code class="broseph-code-pill" title="<?php echo esc_attr( $site_id ); ?>"><?php echo esc_html( $site_id ); ?></code>
							<?php else : ?>
								<span class="broseph-muted"><?php esc_html_e( '—', 'broseph' ); ?></span>
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'Secret', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $has_secret ? $this->badge( 'success', __( 'Configured', 'broseph' ) ) : $this->badge( 'error', __( 'Not set', 'broseph' ) ); ?>
						</dd>
						<dt><?php esc_html_e( 'Open Claw', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->badge( 'default', __( 'Not connected', 'broseph' ) ); ?>
						</dd>
					</dl>
					<a href="<?php echo esc_url( $conn_url ); ?>" class="broseph-card-link">
						<?php esc_html_e( 'Manage Connection →', 'broseph' ); ?>
					</a>
				</div>

				<!-- Site Health -->
				<div class="broseph-card broseph-db-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Site Health', 'broseph' ); ?></h2>
					<dl class="broseph-dl">
						<dt><?php esc_html_e( 'WordPress', 'broseph' ); ?></dt>
						<dd><?php echo esc_html( get_bloginfo( 'version' ) ); ?></dd>
						<dt><?php esc_html_e( 'PHP', 'broseph' ); ?></dt>
						<dd><?php echo esc_html( PHP_VERSION ); ?></dd>
						<dt><?php esc_html_e( 'Theme', 'broseph' ); ?></dt>
						<dd>
							<span class="broseph-text-truncate" title="<?php echo esc_attr( $theme->get( 'Name' ) ); ?>">
								<?php echo esc_html( $theme->get( 'Name' ) ); ?>
							</span>
						</dd>
						<dt><?php esc_html_e( 'Divi', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $divi_active ? $this->badge( 'success', __( 'Active', 'broseph' ) ) : $this->badge( 'default', __( 'Inactive', 'broseph' ) ); ?>
						</dd>
						<dt><?php esc_html_e( 'GitPress', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $gitpress_active ? $this->badge( 'success', __( 'Active', 'broseph' ) ) : $this->badge( 'default', __( 'Inactive', 'broseph' ) ); ?>
						</dd>
					</dl>
				</div>

				<!-- Safety Mode -->
				<div class="broseph-card broseph-db-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Safety Mode', 'broseph' ); ?></h2>
					<dl class="broseph-dl">
						<dt><?php esc_html_e( 'Live Edits', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $live_edits ? $this->badge( 'pending', __( 'Enabled', 'broseph' ) ) : $this->badge( 'success', __( 'Disabled', 'broseph' ) ); ?>
						</dd>
						<dt><?php esc_html_e( 'JS Snippets', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $js_snippets ? $this->badge( 'pending', __( 'Enabled', 'broseph' ) ) : $this->badge( 'success', __( 'Disabled', 'broseph' ) ); ?>
						</dd>
						<dt><?php esc_html_e( 'Prefer GitPress', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $prefer_gp ? $this->badge( 'success', __( 'Enabled', 'broseph' ) ) : $this->badge( 'default', __( 'Disabled', 'broseph' ) ); ?>
						</dd>
					</dl>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="broseph-card-link">
						<?php esc_html_e( 'Edit Settings →', 'broseph' ); ?>
					</a>
				</div>

				<!-- Agent Capabilities -->
				<div class="broseph-card broseph-db-card">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Agent Capabilities', 'broseph' ); ?></h2>
					<dl class="broseph-dl">
						<dt><?php esc_html_e( 'Page Mgmt', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->badge( 'success', __( 'Active', 'broseph' ) ); ?>
						</dd>
						<dt><?php esc_html_e( 'Reports', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->badge( 'success', __( 'Active', 'broseph' ) ); ?>
						</dd>
						<dt><?php esc_html_e( 'Divi Builder', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $divi_active ? $this->badge( 'success', __( 'Available', 'broseph' ) ) : $this->badge( 'default', __( 'Unavailable', 'broseph' ) ); ?>
						</dd>
						<dt><?php esc_html_e( 'GitPress Pages', 'broseph' ); ?></dt>
						<dd>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $gitpress_active ? $this->badge( 'success', __( 'Available', 'broseph' ) ) : $this->badge( 'default', __( 'Unavailable', 'broseph' ) ); ?>
						</dd>
					</dl>
				</div>

				<!-- Recent Activity -->
				<div class="broseph-card broseph-db-card broseph-card-wide">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Recent Activity', 'broseph' ); ?></h2>
					<?php if ( empty( $recent_logs ) ) : ?>
						<p class="broseph-empty-state">
							<?php esc_html_e( 'No activity yet. Signed Open Claw requests and Broseph actions will appear here.', 'broseph' ); ?>
						</p>
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
										<td>
											<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php echo $this->log_status_badge( (string) ( $row['status'] ?? '' ) ); ?>
										</td>
										<td><?php echo esc_html( $row['task_type'] ?? '—' ); ?></td>
										<td class="broseph-td-message"><?php echo esc_html( $row['message'] ?? '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<a href="<?php echo esc_url( $logs_url ); ?>" class="broseph-card-link">
						<?php esc_html_e( 'View all logs →', 'broseph' ); ?>
					</a>
				</div>

				<!-- Latest Report -->
				<div class="broseph-card broseph-db-card broseph-card-wide">
					<h2 class="broseph-card-title"><?php esc_html_e( 'Latest Report', 'broseph' ); ?></h2>
					<?php if ( null === $latest_report ) : ?>
						<p class="broseph-empty-state">
							<?php esc_html_e( 'No reports yet. Run a weekly scan from Open Claw to generate your first report.', 'broseph' ); ?>
						</p>
					<?php else : ?>
						<dl class="broseph-dl">
							<dt><?php esc_html_e( 'Status', 'broseph' ); ?></dt>
							<dd>
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $this->report_status_badge( (string) ( $latest_report['status'] ?? '' ) ); ?>
							</dd>
							<dt><?php esc_html_e( 'Type', 'broseph' ); ?></dt>
							<dd><?php echo esc_html( $latest_report['report_type'] ?? '—' ); ?></dd>
							<dt><?php esc_html_e( 'Generated', 'broseph' ); ?></dt>
							<dd class="broseph-muted"><?php echo esc_html( $latest_report['created_at'] ?? '—' ); ?></dd>
							<dt><?php esc_html_e( 'Summary', 'broseph' ); ?></dt>
							<dd><?php echo esc_html( $latest_report['summary'] ?? '—' ); ?></dd>
						</dl>
					<?php endif; ?>
					<a href="<?php echo esc_url( $reports_url ); ?>" class="broseph-card-link">
						<?php esc_html_e( 'View all reports →', 'broseph' ); ?>
					</a>
				</div>

				<!-- Setup Checklist + Quick Actions -->
				<div class="broseph-card broseph-db-card broseph-card-full">
					<div class="broseph-bottom-row">

						<div class="broseph-checklist-section">
							<h2 class="broseph-card-title"><?php esc_html_e( 'Setup Checklist', 'broseph' ); ?></h2>
							<ul class="broseph-checklist" role="list">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->checklist_item( 'ok', __( 'Broseph plugin active', 'broseph' ) );
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->checklist_item(
									$has_secret ? 'ok' : 'err',
									__( 'Shared secret generated', 'broseph' ),
									$has_secret ? '' : __( 'Generate one on the Connection page', 'broseph' )
								);
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->checklist_item(
									'pending',
									__( 'Open Claw connected', 'broseph' ),
									__( 'Add site credentials to Open Claw to complete setup', 'broseph' )
								);
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->checklist_item(
									$gitpress_active ? 'ok' : 'optional',
									__( 'GitPress integration', 'broseph' ),
									$gitpress_active ? '' : __( 'Optional — install GitPress to enable landing page features', 'broseph' )
								);
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->checklist_item(
									$live_edits ? 'warn' : 'ok',
									__( 'Live edits disabled (safe)', 'broseph' ),
									$live_edits ? __( 'Live edits are currently enabled — disable in Settings for safer operation', 'broseph' ) : ''
								);
								?>
							</ul>
						</div>

						<div class="broseph-quickactions-section">
							<h2 class="broseph-card-title"><?php esc_html_e( 'Quick Actions', 'broseph' ); ?></h2>
							<div class="broseph-quickactions-grid">
								<a href="<?php echo esc_url( $conn_url ); ?>" class="button button-secondary">
									<?php esc_html_e( 'Manage Connection', 'broseph' ); ?>
								</a>
								<a href="<?php echo esc_url( $reports_url ); ?>" class="button button-secondary">
									<?php esc_html_e( 'View Reports', 'broseph' ); ?>
								</a>
								<a href="<?php echo esc_url( $logs_url ); ?>" class="button button-secondary">
									<?php esc_html_e( 'View Logs', 'broseph' ); ?>
								</a>
								<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-secondary">
									<?php esc_html_e( 'Edit Settings', 'broseph' ); ?>
								</a>
							</div>
						</div>

					</div>
				</div>

			</div><!-- .broseph-dashboard-grid -->
		</div><!-- .wrap -->
		<?php
	}

	/* ── Private helpers ────────────────────────────────────────── */

	private function badge( string $variant, string $label ): string {
		return sprintf(
			'<span class="broseph-badge broseph-badge-%s">%s</span>',
			esc_attr( $variant ),
			esc_html( $label )
		);
	}

	private function checklist_item( string $state, string $label, string $note = '' ): string {
		static $icons = array(
			'ok'       => '✓',
			'err'      => '✗',
			'warn'     => '!',
			'pending'  => '○',
			'optional' => '○',
		);
		$icon = $icons[ $state ] ?? '○';
		$out  = sprintf( '<li class="broseph-checklist-item broseph-check-%s">', esc_attr( $state ) );
		$out .= sprintf( '<span class="broseph-check-icon" aria-hidden="true">%s</span>', esc_html( $icon ) );
		$out .= '<span class="broseph-check-label">' . esc_html( $label ) . '</span>';
		if ( $note ) {
			$out .= ' <span class="broseph-check-note">— ' . esc_html( $note ) . '</span>';
		}
		$out .= '</li>';
		return $out;
	}

	private function log_status_badge( string $status ): string {
		$lower = strtolower( $status );
		if ( str_contains( $lower, 'success' ) || 'ok' === $lower || 'accepted' === $lower ) {
			$variant = 'success';
		} elseif ( str_contains( $lower, 'error' ) || str_contains( $lower, 'fail' ) || str_contains( $lower, 'reject' ) ) {
			$variant = 'error';
		} elseif ( str_contains( $lower, 'pending' ) || str_contains( $lower, 'running' ) ) {
			$variant = 'pending';
		} else {
			$variant = 'default';
		}
		return $this->badge( $variant, $status );
	}

	private function report_status_badge( string $status ): string {
		$lower = strtolower( $status );
		if ( 'healthy' === $lower || str_contains( $lower, 'ok' ) ) {
			$variant = 'success';
		} elseif ( 'critical' === $lower || str_contains( $lower, 'error' ) ) {
			$variant = 'error';
		} elseif ( 'needs_review' === $lower || str_contains( $lower, 'warning' ) ) {
			$variant = 'pending';
		} else {
			$variant = 'default';
		}
		return $this->badge( $variant, $status );
	}
}
