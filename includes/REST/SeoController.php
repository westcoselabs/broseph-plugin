<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoController extends BaseController {

	private \Broseph\Services\SeoService $seo;
	private \Broseph\Services\PermissionsService $permissions;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\SeoService $seo,
		\Broseph\Services\PermissionsService $permissions
	) {
		parent::__construct( $signer, $logger );
		$this->seo         = $seo;
		$this->permissions = $permissions;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/seo/page/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_page_seo' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/seo/page/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_update_page_seo' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/seo/bulk-update',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bulk_update' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_get_page_seo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$result  = $this->seo->get_page_seo( $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'   => 'seo_meta_read',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'object_type' => $result['post_type'],
				'object_id'   => $post_id,
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_update_page_seo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_edit_seo_meta() ) {
			return $this->permission_denied( 'broseph_allow_seo_meta_edits' );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$post_id = (int) $request->get_param( 'id' );
		$result  = $this->seo->update_page_seo( $post_id, $body );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'seo_meta_update_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'object_id' => $post_id,
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'       => 'seo_meta_updated',
				'endpoint'        => $request->get_route(),
				'method'          => $request->get_method(),
				'object_type'     => (string) get_post_type( $post_id ),
				'object_id'       => $post_id,
				'before_snapshot' => $this->compact_snapshot( $result['before'] ),
				'after_snapshot'  => $this->compact_snapshot( $result['after'] ),
				'message'         => 'SEO metadata updated for ' . $result['seo_plugin'],
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_bulk_update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_edit_seo_meta() ) {
			return $this->permission_denied( 'broseph_allow_seo_meta_edits' );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$items  = is_array( $body['items'] ?? null ) ? $body['items'] : null;
		if ( null === $items ) {
			return new \WP_Error( 'broseph_bad_request', 'items array is required.', array( 'status' => 400 ) );
		}

		$result = $this->seo->bulk_update( $items );
		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'seo_meta_bulk_update_blocked',
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
				'task_type' => 'seo_meta_bulk_updated',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => sprintf( 'Processed %d SEO metadata update item(s).', count( $result['results'] ) ),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	private function compact_snapshot( array $snapshot ): array {
		return array(
			'title'           => mb_substr( (string) ( $snapshot['title'] ?? '' ), 0, 120 ),
			'description'     => mb_substr( (string) ( $snapshot['description'] ?? '' ), 0, 200 ),
			'focus_keyphrase' => mb_substr( (string) ( $snapshot['focus_keyphrase'] ?? '' ), 0, 120 ),
			'slug'            => mb_substr( (string) ( $snapshot['slug'] ?? '' ), 0, 120 ),
		);
	}
}
