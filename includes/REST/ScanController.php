<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScanController extends BaseController {

	private \Broseph\Services\SiteScanner $scanner;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\SiteScanner $scanner
	) {
		parent::__construct( $signer, $logger );
		$this->scanner = $scanner;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_scan' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_scan( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->log(
			'scan_started',
			array(
				'task_type' => 'site_scan',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		$data = $this->scanner->scan();

		$this->logger->log(
			'scan_complete',
			array(
				'task_type' => 'site_scan',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => sprintf( 'Scanned %d active plugins.', count( $data['active_plugins'] ) ),
			)
		);

		return new \WP_REST_Response( $data, 200 );
	}
}
