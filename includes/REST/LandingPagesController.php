<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LandingPagesController extends BaseController {

	private \Broseph\Services\LandingPageService $service;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\LandingPageService $service
	) {
		parent::__construct( $signer, $logger );
		$this->service = $service;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/landing-pages/create',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		// Source page for before-snapshot logging.
		$template_id = isset( $body['template_page_id'] ) ? (int) $body['template_page_id'] : 0;
		$page_id     = isset( $body['page_id'] ) ? (int) $body['page_id'] : 0;
		$src_id      = $template_id ?: $page_id;
		$source      = $src_id > 0 ? get_post( $src_id ) : null;

		$before = $source ? array(
			'id'             => $source->ID,
			'title'          => $source->post_title,
			'status'         => $source->post_status,
			'content_length' => strlen( $source->post_content ),
		) : null;

		$result = $this->service->create( $body );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'landing_page_failed',
				array(
					'task_type'       => 'create_landing_page',
					'endpoint'        => $request->get_route(),
					'method'          => $request->get_method(),
					'message'         => $result->get_error_message(),
					'before_snapshot' => $before,
				)
			);
			return $result;
		}

		$new_page_id = $result['page_id'] ?? ( $result['target_page_id'] ?? 0 );

		$this->logger->log(
			'landing_page_created',
			array(
				'task_type'       => 'create_landing_page',
				'endpoint'        => $request->get_route(),
				'method'          => $request->get_method(),
				'object_type'     => 'page',
				'object_id'       => $new_page_id,
				'before_snapshot' => $before,
				'after_snapshot'  => array(
					'page_id'  => $new_page_id,
					'status'   => 'draft',
					'mode'     => $body['mode'] ?? 'template_native',
					'strategy' => $result['strategy'] ?? null,
				),
			)
		);

		return new \WP_REST_Response( $result, 201 );
	}
}
