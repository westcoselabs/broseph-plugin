<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PagesController extends BaseController {

	private \Broseph\Services\PageService $pages;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\PageService $pages
	) {
		parent::__construct( $signer, $logger );
		$this->pages = $pages;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/pages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/pages/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/pages/create-draft',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create_draft' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/pages/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_duplicate' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_list( \WP_REST_Request $request ): \WP_REST_Response {
		$params = array(
			'status'   => $request->get_param( 'status' ) ?? 'publish,draft,pending,private',
			'search'   => $request->get_param( 'search' ) ?? '',
			'per_page' => $request->get_param( 'per_page' ) ?? 50,
			'page'     => $request->get_param( 'page' ) ?? 1,
		);

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'pages_list',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		return new \WP_REST_Response( $this->pages->get_pages( $params ), 200 );
	}

	public function handle_get( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$page = $this->pages->get_page( $id );

		if ( null === $page ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'   => 'page_read',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'object_type' => 'page',
				'object_id'   => $id,
			)
		);

		return new \WP_REST_Response( $page, 200 );
	}

	public function handle_create_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$result = $this->pages->create_draft( $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_id = $result;
		$post   = get_post( $new_id );

		$this->logger->log(
			'page_created',
			array(
				'task_type'      => 'create_draft',
				'endpoint'       => $request->get_route(),
				'method'         => $request->get_method(),
				'object_type'    => 'page',
				'object_id'      => $new_id,
				'after_snapshot' => $post ? array( 'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status ) : null,
			)
		);

		return new \WP_REST_Response(
			array(
				'new_page_id' => $new_id,
				'status'      => 'draft',
				'preview_url' => get_preview_post_link( $new_id ),
				'edit_url'    => admin_url( 'post.php?post=' . $new_id . '&action=edit' ),
			),
			201
		);
	}

	public function handle_duplicate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$source_id = isset( $body['source_page_id'] ) ? (int) $body['source_page_id'] : 0;
		if ( $source_id <= 0 ) {
			return new \WP_Error( 'broseph_bad_request', 'source_page_id is required.', array( 'status' => 400 ) );
		}

		$source = get_post( $source_id );
		$before = $source ? array( 'id' => $source->ID, 'title' => $source->post_title, 'status' => $source->post_status ) : null;

		$result = $this->pages->duplicate(
			$source_id,
			$body['new_title'] ?? '',
			$body['new_slug'] ?? '',
			(bool) ( $body['copy_meta'] ?? true )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_id = $result;
		$post   = get_post( $new_id );

		$this->logger->log(
			'page_duplicated',
			array(
				'task_type'      => 'duplicate_page',
				'endpoint'       => $request->get_route(),
				'method'         => $request->get_method(),
				'object_type'    => 'page',
				'object_id'      => $new_id,
				'before_snapshot' => $before,
				'after_snapshot' => $post ? array( 'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status ) : null,
			)
		);

		return new \WP_REST_Response(
			array(
				'new_page_id' => $new_id,
				'status'      => 'draft',
				'preview_url' => get_preview_post_link( $new_id ),
				'edit_url'    => admin_url( 'post.php?post=' . $new_id . '&action=edit' ),
			),
			201
		);
	}
}
