<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpdatesController extends BaseController {

	private \Broseph\Services\UpdateService $updates;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\UpdateService $updates
	) {
		parent::__construct( $signer, $logger );
		$this->updates = $updates;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/updates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_check' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/updates/plugins/update-selected',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_update_plugins' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/updates/themes/update-selected',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_update_themes' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_check( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->updates->get_available_updates();

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'updates_check',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => sprintf(
					'updates_check: %d plugin(s), %d theme(s)',
					count( $result['plugins'] ),
					count( $result['themes'] )
				),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_update_plugins( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$confirm = (string) ( $body['confirm'] ?? '' );
		if ( 'UPDATE_SELECTED_PLUGINS' !== $confirm ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'plugin_update_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => 'Missing or invalid confirm token.',
				)
			);
			return new \WP_Error(
				'broseph_missing_confirm',
				'confirm must be exactly "UPDATE_SELECTED_PLUGINS".',
				array( 'status' => 400 )
			);
		}

		$plugin_files = is_array( $body['plugin_files'] ?? null ) ? $body['plugin_files'] : null;
		if ( null === $plugin_files || empty( $plugin_files ) ) {
			return new \WP_Error(
				'broseph_bad_request',
				'plugin_files is required and must be a non-empty array.',
				array( 'status' => 400 )
			);
		}

		$allow_inactive = (bool) ( $body['allow_inactive'] ?? false );

		$result = $this->updates->update_plugins( $plugin_files, $allow_inactive );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'plugin_update_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$summary = $this->summarise( $result );

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'plugins_updated',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => sprintf(
					'plugin_update: updated=%d skipped=%d failed=%d',
					$summary['updated'],
					$summary['skipped'],
					$summary['failed']
				),
			)
		);

		return new \WP_REST_Response(
			array(
				'results' => $result,
				'summary' => $summary,
			),
			200
		);
	}

	public function handle_update_themes( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$confirm = (string) ( $body['confirm'] ?? '' );
		if ( 'UPDATE_SELECTED_THEMES' !== $confirm ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'theme_update_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => 'Missing or invalid confirm token.',
				)
			);
			return new \WP_Error(
				'broseph_missing_confirm',
				'confirm must be exactly "UPDATE_SELECTED_THEMES".',
				array( 'status' => 400 )
			);
		}

		$themes = is_array( $body['themes'] ?? null ) ? $body['themes'] : null;
		if ( null === $themes || empty( $themes ) ) {
			return new \WP_Error(
				'broseph_bad_request',
				'themes is required and must be a non-empty array.',
				array( 'status' => 400 )
			);
		}

		$allow_active_theme = (bool) ( $body['allow_active_theme'] ?? false );

		$result = $this->updates->update_themes( $themes, $allow_active_theme );

		if ( is_wp_error( $result ) ) {
			$this->logger->log(
				'rejected',
				array(
					'task_type' => 'theme_update_blocked',
					'endpoint'  => $request->get_route(),
					'method'    => $request->get_method(),
					'message'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		$summary = $this->summarise( $result );

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'themes_updated',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => sprintf(
					'theme_update: updated=%d skipped=%d failed=%d',
					$summary['updated'],
					$summary['skipped'],
					$summary['failed']
				),
			)
		);

		return new \WP_REST_Response(
			array(
				'results' => $result,
				'summary' => $summary,
			),
			200
		);
	}

	private function summarise( array $results ): array {
		$updated = 0;
		$skipped = 0;
		$failed  = 0;
		foreach ( $results as $row ) {
			match ( $row['status'] ?? '' ) {
				'updated' => $updated++,
				'skipped' => $skipped++,
				default   => $failed++,
			};
		}
		return array( 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed );
	}
}
