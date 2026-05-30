<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReportBuilder {

	private SiteScanner $scanner;
	private GitPressIntegration $gitpress;
	private \Broseph\Logging\AuditLogRepository $log_repo;
	private \Broseph\Logging\ReportRepository $report_repo;

	public function __construct(
		SiteScanner $scanner,
		GitPressIntegration $gitpress,
		\Broseph\Logging\AuditLogRepository $log_repo,
		\Broseph\Logging\ReportRepository $report_repo
	) {
		$this->scanner     = $scanner;
		$this->gitpress    = $gitpress;
		$this->log_repo    = $log_repo;
		$this->report_repo = $report_repo;
	}

	public function build_weekly(): array {
		$scan     = $this->scanner->scan();
		$checks   = array();
		$recs     = array();
		$approvals = array();

		// Plugin updates.
		$updates = $scan['available_plugin_updates'];
		if ( ! empty( $updates ) ) {
			$checks[]   = array(
				'type'    => 'plugin_updates',
				'status'  => 'warning',
				'count'   => count( $updates ),
				'message' => count( $updates ) . ' plugin update(s) available.',
			);
			$names      = array_column( $updates, 'plugin_file' );
			$recs[]     = 'Update ' . count( $updates ) . ' plugin(s): ' . implode( ', ', $names );
			$approvals[] = 'Plugin updates pending approval.';
		} else {
			$checks[] = array(
				'type'    => 'plugin_updates',
				'status'  => 'ok',
				'count'   => 0,
				'message' => 'All plugins are up to date.',
			);
		}

		// GitPress status.
		$gp_active = $this->gitpress->is_active();
		$gp_sc     = $this->gitpress->is_shortcode_registered();
		if ( $gp_active && $gp_sc ) {
			$usages   = $this->gitpress->find_usages();
			$checks[] = array(
				'type'         => 'gitpress',
				'status'       => 'ok',
				'usages_count' => count( $usages ),
				'message'      => 'GitPress active. ' . count( $usages ) . ' shortcode usage(s) found.',
			);
		} elseif ( $gp_active ) {
			$checks[] = array( 'type' => 'gitpress', 'status' => 'warning', 'message' => 'GitPress plugin active but shortcode not registered.' );
			$recs[]   = 'Verify GitPress plugin configuration.';
		} else {
			$checks[] = array( 'type' => 'gitpress', 'status' => 'info', 'message' => 'GitPress not active on this site.' );
		}

		// Recent error/rejected logs (last 7 days).
		$error_count = $this->log_repo->count_recent_errors();
		if ( $error_count > 10 ) {
			$checks[] = array( 'type' => 'recent_errors', 'status' => 'critical', 'count' => $error_count, 'message' => "{$error_count} error/rejected log entries in the last 7 days." );
		} elseif ( $error_count > 0 ) {
			$checks[] = array( 'type' => 'recent_errors', 'status' => 'warning', 'count' => $error_count, 'message' => "{$error_count} error/rejected log entries in the last 7 days." );
		} else {
			$checks[] = array( 'type' => 'recent_errors', 'status' => 'ok', 'count' => 0, 'message' => 'No error logs in the last 7 days.' );
		}

		// Pages summary.
		$page_counts = wp_count_posts( 'page' );
		$draft_count = (int) ( $page_counts->draft ?? 0 );
		$pub_count   = (int) ( $page_counts->publish ?? 0 );
		$checks[]    = array(
			'type'    => 'pages',
			'status'  => 'info',
			'message' => "{$pub_count} published page(s), {$draft_count} draft(s).",
		);

		// Form plugins.
		$form_plugins = $scan['detected_form_plugins'];
		$checks[]     = array(
			'type'     => 'form_plugins',
			'status'   => 'info',
			'detected' => $form_plugins,
			'message'  => empty( $form_plugins )
				? 'No form plugins detected.'
				: 'Form plugins: ' . implode( ', ', $form_plugins ),
		);

		// Mail plugins.
		$mail_plugins = $scan['detected_mail_plugins'];
		$checks[]     = array(
			'type'     => 'mail_plugins',
			'status'   => 'info',
			'detected' => $mail_plugins,
			'message'  => empty( $mail_plugins )
				? 'No mail plugins detected.'
				: 'Mail plugins: ' . implode( ', ', $mail_plugins ),
		);

		// Determine overall status.
		$has_critical = ! empty(
			array_filter( $checks, static fn( $c ) => ( $c['status'] ?? '' ) === 'critical' )
		);
		$has_warning  = ! empty(
			array_filter( $checks, static fn( $c ) => ( $c['status'] ?? '' ) === 'warning' )
		);

		$status  = 'healthy';
		if ( $has_critical ) {
			$status = 'critical';
		} elseif ( $has_warning ) {
			$status = 'needs_review';
		}

		$summary = sprintf(
			'Weekly report for %s — %d checks performed. Status: %s.',
			site_url(),
			count( $checks ),
			$status
		);

		$report = array(
			'status'            => $status,
			'summary'           => $summary,
			'site_url'          => site_url(),
			'generated_at'      => current_time( 'c', true ),
			'checks'            => $checks,
			'recommendations'   => $recs,
			'approval_required' => $approvals,
		);

		// Persist to reports table.
		$this->report_repo->insert( array(
			'report_type' => 'weekly',
			'status'      => $status,
			'summary'     => $summary,
			'report_json' => wp_json_encode( $report ),
			'created_at'  => current_time( 'mysql', true ),
		) );

		return $report;
	}
}
