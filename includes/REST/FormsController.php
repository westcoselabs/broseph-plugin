<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormsController extends BaseController {

	private \Broseph\Services\FormService $forms;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\FormService $forms
	) {
		parent::__construct( $signer, $logger );
		$this->forms = $forms;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/forms',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/forms/page/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_page' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/forms/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_list( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'forms_list',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		return new \WP_REST_Response( $this->forms->get_all_forms(), 200 );
	}

	public function handle_page( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->forms->get_forms_on_page( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'   => 'forms_page_scan',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'object_type' => 'page',
				'object_id'   => $id,
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$result = $this->forms->get_test_info( $body );

		$this->logger->log(
			'accepted',
			array(
				'task_type'  => 'form_test_requested',
				'endpoint'   => $request->get_route(),
				'method'     => $request->get_method(),
				'message'    => 'form_test: unsupported in v1 for form_type=' . ( $body['form_type'] ?? 'unknown' ),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}
}
