<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReportsController extends BaseController {

	private \Broseph\Services\ReportBuilder $builder;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\ReportBuilder $builder
	) {
		parent::__construct( $signer, $logger );
		$this->builder = $builder;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/reports/weekly',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_weekly' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_weekly( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->log(
			'report_started',
			array(
				'task_type' => 'weekly_report',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		$report = $this->builder->build_weekly();

		$this->logger->log(
			'report_complete',
			array(
				'task_type' => 'weekly_report',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => $report['summary'] ?? '',
			)
		);

		return new \WP_REST_Response( $report, 200 );
	}
}
