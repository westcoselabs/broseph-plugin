<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseController {

	public function __construct(
		protected \Broseph\Auth\RequestSigner $signer,
		protected \Broseph\Logging\ActionLogger $logger
	) {}

	/**
	 * Permission callback for all signed Broseph endpoints.
	 * Rejects and logs failed auth; accepts silently (route callback logs success).
	 */
	public function require_signed( \WP_REST_Request $request ): bool|\WP_Error {
		$result = $this->signer->verify( $request );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'endpoint' => $request->get_route(),
					'method'   => $request->get_method(),
					'message'  => $result->get_error_message(),
				)
			);
		}

		return $result;
	}

	/**
	 * Standard 403 response when a Broseph permission setting is disabled.
	 * The permission key is included so Open Claw knows exactly which toggle to check.
	 */
	protected function permission_denied( string $permission ): \WP_Error {
		return new \WP_Error(
			'broseph_permission_denied',
			'This action is disabled in Broseph permissions.',
			array(
				'status'     => 403,
				'permission' => $permission,
			)
		);
	}

	abstract public function register_routes( string $namespace ): void;
}
