<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitPressController extends BaseController {

	private \Broseph\Services\GitPressIntegration $gitpress;
	private \Broseph\Services\PermissionsService $permissions;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\GitPressIntegration $gitpress,
		\Broseph\Services\PermissionsService $permissions
	) {
		parent::__construct( $signer, $logger );
		$this->gitpress   = $gitpress;
		$this->permissions = $permissions;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/gitpress/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/gitpress/usages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_usages' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/gitpress/page/(?P<id>\d+)/usages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_page_usages' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/gitpress/validate-shortcode',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_validate' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/gitpress/pages/create',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create_page' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/gitpress/pages/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_page_settings' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);
	}

	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		$this->log_accepted( $request, 'gitpress_status' );

		return new \WP_REST_Response(
			array(
				'is_active'               => $this->gitpress->is_active(),
				'shortcode_registered'    => $this->gitpress->is_shortcode_registered(),
				'shortcode_name'          => 'divi_github_content',
				'plugin_detected_name'    => $this->gitpress->get_detected_plugin(),
				'can_scan_usages'         => true,
				'cache_purge_supported'   => false,
			),
			200
		);
	}

	public function handle_usages( \WP_REST_Request $request ): \WP_REST_Response {
		$this->log_accepted( $request, 'gitpress_usages_scan' );
		$usages = $this->gitpress->find_usages();
		return new \WP_REST_Response( array( 'usages' => $usages, 'count' => count( $usages ) ), 200 );
	}

	public function handle_page_usages( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$this->log_accepted( $request, 'gitpress_page_usages', 'page', $id );
		$usages = $this->gitpress->find_usages_on_page( $id );
		return new \WP_REST_Response( array( 'page_id' => $id, 'usages' => $usages, 'count' => count( $usages ) ), 200 );
	}

	public function handle_validate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body      = $request->get_json_params();
		$shortcode = is_array( $body ) ? ( $body['shortcode'] ?? '' ) : '';

		if ( empty( $shortcode ) ) {
			return new \WP_Error( 'broseph_bad_request', '"shortcode" field is required.', array( 'status' => 400 ) );
		}

		$this->log_accepted( $request, 'gitpress_validate_shortcode' );
		$result = $this->gitpress->validate_shortcode( (string) $shortcode );

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_create_page( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_create_gitpress_pages() ) {
			return $this->permission_denied( 'can_create_gitpress_pages' );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$result = $this->gitpress->create_page( $body );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'gitpress_page_create',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$this->logger->log(
			'page_created',
			array(
				'task_type'      => 'gitpress_page_create',
				'endpoint'       => $request->get_route(),
				'method'         => $request->get_method(),
				'object_type'    => 'page',
				'object_id'      => $result['page_id'],
				'after_snapshot' => array(
					'id'               => $result['page_id'],
					'status'           => 'draft',
					'render_position'  => $result['render_position'],
					'full_page_canvas' => $result['full_page_canvas'],
				),
			)
		);

		return new \WP_REST_Response( $result, 201 );
	}

	public function handle_get_page_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->gitpress->get_page_settings( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_accepted( $request, 'gitpress_page_settings', 'page', $id );

		return new \WP_REST_Response( $result, 200 );
	}

	private function log_accepted( \WP_REST_Request $request, string $task_type, string $object_type = '', int $object_id = 0 ): void {
		$context = array(
			'task_type' => $task_type,
			'endpoint'  => $request->get_route(),
			'method'    => $request->get_method(),
		);
		if ( $object_type ) {
			$context['object_type'] = $object_type;
		}
		if ( $object_id ) {
			$context['object_id'] = $object_id;
		}
		$this->logger->log( 'accepted', $context );
	}
}
