<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PagesController extends BaseController {

	private \Broseph\Services\PageService $pages;
	private \Broseph\Services\GitPressIntegration $gitpress;
	private \Broseph\Services\PermissionsService $permissions;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\PageService $pages,
		\Broseph\Services\GitPressIntegration $gitpress,
		\Broseph\Services\PermissionsService $permissions
	) {
		parent::__construct( $signer, $logger );
		$this->pages       = $pages;
		$this->gitpress    = $gitpress;
		$this->permissions = $permissions;
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

		register_rest_route(
			$namespace,
			'/pages/(?P<id>\d+)/publish',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_publish' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/pages/(?P<id>\d+)/convert-to-gitpress',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_convert_to_gitpress' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/pages/bulk-publish',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bulk_publish' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/pages/(?P<id>\d+)/trash',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_trash' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/pages/bulk-trash',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bulk_trash' ),
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

	public function handle_publish( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_publish_pages() ) {
			return $this->publish_permission_denied();
		}

		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$page_id  = (int) $request->get_param( 'id' );
		$expected = isset( $body['expected_current_status'] ) ? (string) $body['expected_current_status'] : null;
		$result   = $this->pages->publish_page( $page_id, $expected );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'publish_page_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'object_id' => $page_id,
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'       => 'page_published',
				'endpoint'        => $request->get_route(),
				'method'          => $request->get_method(),
				'object_type'     => 'page',
				'object_id'       => $page_id,
				'before_snapshot' => array( 'status' => $result['previous_status'] ),
				'after_snapshot'  => array( 'status' => $result['new_status'] ),
				'message'         => sprintf( 'previous_status=%s new_status=%s', $result['previous_status'], $result['new_status'] ),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_convert_to_gitpress( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$page_id = (int) $request->get_param( 'id' );

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'convert_page_to_gitpress_requested',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'object_type' => 'page',
				'object_id' => $page_id,
			)
		);

		if ( ! $this->permissions->can_convert_pages_to_gitpress() ) {
			$error = $this->convert_permission_denied();
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'convert_page_to_gitpress_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'object_id' => $page_id,
					'message'   => $error->get_error_message(),
				)
			);
			return $error;
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$result = $this->gitpress->convert_page_to_gitpress( $page_id, $body );
		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'convert_page_to_gitpress_failed',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'object_id' => $page_id,
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		if ( ! empty( $result['backup']['created'] ) ) {
			$this->logger->log(
				'accepted',
				array(
					'task_type' => 'convert_page_to_gitpress_backup_created',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'object_type' => 'page',
					'object_id' => (int) $result['backup']['backup_page_id'],
					'message'   => 'Backup draft created before GitPress conversion.',
				)
			);
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'       => 'convert_page_to_gitpress_' . $result['status'],
				'endpoint'        => $request->get_route(),
				'method'          => $request->get_method(),
				'object_type'     => 'page',
				'object_id'       => $page_id,
				'before_snapshot' => $result['before'] ?? null,
				'after_snapshot'  => $result['after'] ?? null,
				'message'         => 'GitPress conversion status: ' . $result['status'],
			)
		);

		return new \WP_REST_Response( $result, 'partial' === $result['status'] ? 207 : 200 );
	}

	public function handle_bulk_publish( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_publish_pages() ) {
			return $this->publish_permission_denied();
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$page_ids = is_array( $body['page_ids'] ?? null ) ? $body['page_ids'] : null;
		if ( null === $page_ids ) {
			return new \WP_Error( 'broseph_bad_request', 'page_ids array is required.', array( 'status' => 400 ) );
		}

		$result = $this->pages->bulk_publish_pages( $page_ids );
		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'bulk_publish_pages_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'bulk_publish_pages',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => sprintf(
					'requested=%d published=%d failed=%d skipped=%d',
					$result['summary']['requested'],
					$result['summary']['published'],
					$result['summary']['failed'],
					$result['summary']['skipped']
				),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_trash( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_delete_drafts() ) {
			return $this->trash_permission_denied();
		}

		$page_id = (int) $request->get_param( 'id' );
		$result  = $this->pages->trash_draft_page( $page_id );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'trash_draft_page_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'object_id' => $page_id,
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'       => 'draft_page_trashed',
				'endpoint'        => $request->get_route(),
				'method'          => $request->get_method(),
				'object_type'     => 'page',
				'object_id'       => $page_id,
				'before_snapshot' => array( 'status' => $result['previous_status'] ),
				'after_snapshot'  => array( 'status' => $result['new_status'] ),
				'message'         => sprintf( 'previous_status=%s new_status=%s', $result['previous_status'], $result['new_status'] ),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_bulk_trash( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_delete_drafts() ) {
			return $this->trash_permission_denied();
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$page_ids = is_array( $body['page_ids'] ?? null ) ? $body['page_ids'] : null;
		if ( null === $page_ids ) {
			return new \WP_Error( 'broseph_bad_request', 'page_ids array is required.', array( 'status' => 400 ) );
		}

		$result = $this->pages->bulk_trash_pages( $page_ids );
		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'bulk_trash_pages_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'bulk_trash_pages',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => sprintf(
					'requested=%d trashed=%d failed=%d skipped=%d',
					$result['summary']['requested'],
					$result['summary']['trashed'],
					$result['summary']['failed'],
					$result['summary']['skipped']
				),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	private function publish_permission_denied(): \WP_Error {
		return new \WP_Error(
			'broseph_permission_denied',
			'Publishing pages is disabled in Broseph permissions.',
			array(
				'status'     => 403,
				'permission' => 'can_publish_pages',
			)
		);
	}

	private function trash_permission_denied(): \WP_Error {
		return new \WP_Error(
			'broseph_permission_denied',
			'Trashing drafts is disabled in Broseph permissions.',
			array(
				'status'     => 403,
				'permission' => 'can_delete_drafts',
			)
		);
	}

	private function convert_permission_denied(): \WP_Error {
		return new \WP_Error(
			'broseph_permission_denied',
			'Converting pages to GitPress is disabled in Broseph permissions.',
			array(
				'status'     => 403,
				'permission' => 'can_convert_pages_to_gitpress',
			)
		);
	}
}
