<?php
declare( strict_types=1 );

namespace Broseph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static ?Plugin $instance = null;

	private function __construct() {
		// Core infrastructure.
		$log_repo   = new Logging\AuditLogRepository();
		$logger     = new Logging\ActionLogger( $log_repo );
		$report_repo = new Logging\ReportRepository();
		$signer     = new Auth\RequestSigner();

		// Domain services.
		$scanner           = new Services\SiteScanner();
		$page_service      = new Services\PageService();
		$gitpress          = new Services\GitPressIntegration();
		$divi_service      = new Services\DiviService();
		$strategy_resolver = new Services\ContentStrategyResolver( $gitpress, $divi_service, $page_service );
		$landing_pages     = new Services\LandingPageService( $gitpress, $strategy_resolver, $divi_service );
		$report_builder    = new Services\ReportBuilder( $scanner, $gitpress, $log_repo, $report_repo );

		// Admin pages.
		$settings_page = new Admin\SettingsPage();
		$logs_page     = new Admin\LogsPage( $log_repo );
		$reports_page  = new Admin\ReportsPage( $report_repo );

		// REST hub.
		$routes = new REST\Routes(
			$signer,
			$logger,
			$scanner,
			$page_service,
			$gitpress,
			$divi_service,
			$strategy_resolver,
			$landing_pages,
			$report_builder
		);

		$settings_page->init();
		$logs_page->init();
		$reports_page->init();
		$routes->init();
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
