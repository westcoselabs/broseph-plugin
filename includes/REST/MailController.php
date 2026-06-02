<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailController extends BaseController {

	private \Broseph\Services\MailTestService $mail_test;
	private \Broseph\Services\MailLogService $mail_log;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\MailTestService $mail_test,
		\Broseph\Services\MailLogService $mail_log
	) {
		parent::__construct( $signer, $logger );
		$this->mail_test = $mail_test;
		$this->mail_log  = $mail_log;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/mail/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/mail/logs/recent',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_logs' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$result = $this->mail_test->send_test( $body );

		// Log only masked recipient and outcome — never the body or real address.
		$this->logger->log(
			'accepted',
			array(
				'task_type'         => 'mail_test',
				'endpoint'          => $request->get_route(),
				'method'            => $request->get_method(),
				'message'           => sprintf(
					'mail_test %s → %s',
					$result['status'] ?? 'unknown',
					$result['recipient_masked'] ?? '***'
				),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_logs( \WP_REST_Request $request ): \WP_REST_Response {
		$limit  = (int) ( $request->get_param( 'limit' ) ?? 20 );
		$result = $this->mail_log->get_recent_logs( $limit );

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'mail_logs_read',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}
}
