<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiviController extends BaseController {

	private \Broseph\Services\DiviService $divi;
	private \Broseph\Services\GitPressIntegration $gitpress;
	private \Broseph\Services\LandingPageService $landing_pages;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\DiviService $divi,
		\Broseph\Services\GitPressIntegration $gitpress,
		\Broseph\Services\LandingPageService $landing_pages
	) {
		parent::__construct( $signer, $logger );
		$this->divi          = $divi;
		$this->gitpress      = $gitpress;
		$this->landing_pages = $landing_pages;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/divi/page/(?P<id>\d+)/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_page_summary' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/divi/code-module/insert-gitpress',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_insert_gitpress' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/divi/pages/create-from-template',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_create_from_template' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_page_summary( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'page' !== $post->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'   => 'divi_page_summary',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'object_type' => 'page',
				'object_id'   => $id,
			)
		);

		return new \WP_REST_Response( $this->divi->get_page_summary( $id ), 200 );
	}

	public function handle_insert_gitpress( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$page_id    = isset( $body['page_id'] ) ? (int) $body['page_id'] : 0;
		$shortcode  = trim( (string) ( $body['shortcode'] ?? '' ) );
		$placement  = is_array( $body['placement'] ?? null ) ? $body['placement'] : array( 'mode' => 'append_to_page' );
		$save_mode  = (string) ( $body['save_mode'] ?? 'draft_copy' );

		if ( $page_id <= 0 ) {
			return new \WP_Error( 'broseph_bad_request', 'page_id is required.', array( 'status' => 400 ) );
		}
		if ( empty( $shortcode ) ) {
			return new \WP_Error( 'broseph_bad_request', 'shortcode is required.', array( 'status' => 400 ) );
		}

		// Validate shortcode.
		$validation = $this->gitpress->validate_shortcode( $shortcode );
		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'broseph_invalid_shortcode',
				'Shortcode validation failed: ' . implode( ' ', $validation['errors'] ),
				array( 'status' => 422 )
			);
		}

		// Validate save mode.
		$allow_live_edits = (bool) get_option( 'broseph_allow_live_edits', '0' );
		if ( 'live_edit' === $save_mode && ! $allow_live_edits ) {
			return new \WP_Error(
				'broseph_live_edits_disabled',
				'live_edit save mode is disabled in plugin settings.',
				array( 'status' => 403 )
			);
		}

		$source = get_post( $page_id );
		if ( ! $source || 'page' !== $source->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$before_snapshot = array(
			'id'             => $source->ID,
			'status'         => $source->post_status,
			'content_length' => strlen( $source->post_content ),
		);

		$placement_mode = (string) ( $placement['mode'] ?? 'append_to_page' );

		$new_content = $this->build_new_content( $source->post_content, $shortcode, $placement_mode, $placement );
		if ( is_wp_error( $new_content ) ) {
			return $new_content;
		}

		$target_id = $this->apply_save_mode( $source, $new_content, $save_mode );
		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}

		$target      = get_post( $target_id );
		$after_snap  = $target ? array(
			'id'             => $target->ID,
			'status'         => $target->post_status,
			'content_length' => strlen( $target->post_content ),
		) : null;

		$this->logger->log(
			'code_module_inserted',
			array(
				'task_type'       => 'insert_gitpress_shortcode',
				'endpoint'        => $request->get_route(),
				'method'          => $request->get_method(),
				'object_type'     => 'page',
				'object_id'       => $target_id,
				'before_snapshot' => $before_snapshot,
				'after_snapshot'  => $after_snap,
			)
		);

		return new \WP_REST_Response(
			array(
				'target_page_id'   => $target_id,
				'original_page_id' => $page_id,
				'preview_url'      => get_preview_post_link( $target_id ),
				'inserted_shortcode' => $shortcode,
				'save_mode_used'   => $save_mode,
			),
			201
		);
	}

	public function handle_create_from_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$template_id = isset( $body['template_page_id'] ) ? (int) $body['template_page_id'] : 0;

		// Capture before snapshot while template still exists.
		$template = get_post( $template_id );
		$before_snapshot = $template ? array(
			'id'             => $template->ID,
			'title'          => $template->post_title,
			'status'         => $template->post_status,
			'content_length' => strlen( $template->post_content ),
		) : null;

		$result = $this->landing_pages->create_from_divi_template( $body );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'divi_create_from_template',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$after_snapshot = array(
			'id'     => $result['page_id'],
			'status' => 'draft',
		);

		$this->logger->log(
			'page_created',
			array(
				'task_type'       => 'divi_create_from_template',
				'endpoint'        => $request->get_route(),
				'method'          => $request->get_method(),
				'object_type'     => 'page',
				'object_id'       => $result['page_id'],
				'before_snapshot' => $before_snapshot,
				'after_snapshot'  => $after_snapshot,
				'message'         => sprintf(
					'divi_from_template: template=%d new_page=%d cm_inserted=%d',
					$template_id,
					$result['page_id'],
					$result['inserted_code_modules']
				),
			)
		);

		return new \WP_REST_Response( $result, 201 );
	}

	private function build_new_content( string $content, string $shortcode, string $mode, array $placement ): string|\WP_Error {
		if ( 'append_to_page' === $mode ) {
			return $this->divi->append_code_module( $content, $shortcode );
		}

		if ( 'after_module_index' === $mode ) {
			$idx    = isset( $placement['module_index'] ) ? (int) $placement['module_index'] : -1;
			if ( $idx < 0 ) {
				return new \WP_Error( 'broseph_bad_request', 'placement.module_index is required for after_module_index mode.', array( 'status' => 400 ) );
			}
			$result = $this->divi->insert_code_module_after( $content, $idx, $shortcode );
			if ( null === $result ) {
				return new \WP_Error(
					'broseph_parse_unsafe',
					'Cannot safely determine insertion position for after_module_index. Use append_to_page instead.',
					array( 'status' => 422 )
				);
			}
			return $result;
		}

		return new \WP_Error( 'broseph_bad_request', 'Unsupported placement mode: ' . $mode, array( 'status' => 400 ) );
	}

	private function apply_save_mode( \WP_Post $source, string $new_content, string $save_mode ): int|\WP_Error {
		if ( 'live_edit' === $save_mode ) {
			$result = wp_update_post( array( 'ID' => $source->ID, 'post_content' => $new_content ), true );
			return is_wp_error( $result ) ? $result : $source->ID;
		}

		if ( 'update_draft_only' === $save_mode ) {
			if ( 'draft' !== $source->post_status ) {
				return new \WP_Error(
					'broseph_not_draft',
					'update_draft_only requires the target page to be a draft.',
					array( 'status' => 422 )
				);
			}
			$result = wp_update_post( array( 'ID' => $source->ID, 'post_content' => $new_content ), true );
			return is_wp_error( $result ) ? $result : $source->ID;
		}

		// Default: draft_copy
		$new_id = wp_insert_post(
			array(
				'post_title'   => $source->post_title . ' (Broseph Draft)',
				'post_name'    => $source->post_name . '-broseph-draft',
				'post_excerpt' => $source->post_excerpt,
				'post_content' => $new_content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);

		return is_wp_error( $new_id ) ? $new_id : $new_id;
	}
}
