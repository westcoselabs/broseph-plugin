<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Routes {

	private const NAMESPACE = 'broseph/v1';

	private \Broseph\Auth\RequestSigner $signer;
	private \Broseph\Logging\ActionLogger $logger;
	private \Broseph\Services\SiteScanner $scanner;
	private \Broseph\Services\PageService $pages;
	private \Broseph\Services\GitPressIntegration $gitpress;
	private \Broseph\Services\DiviService $divi;
	private \Broseph\Services\ContentStrategyResolver $strategy;
	private \Broseph\Services\LandingPageService $landing_pages;
	private \Broseph\Services\ReportBuilder $report_builder;
	private \Broseph\Services\FormService $form_service;
	private \Broseph\Services\DiviContactFormTestAdapter $divi_form_test;
	private \Broseph\Services\MailLogService $mail_log;
	private \Broseph\Services\MailTestService $mail_test;
	private \Broseph\Services\UpdateService $update_service;
	private \Broseph\Services\PermissionsService $permissions;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\SiteScanner $scanner,
		\Broseph\Services\PageService $pages,
		\Broseph\Services\GitPressIntegration $gitpress,
		\Broseph\Services\DiviService $divi,
		\Broseph\Services\ContentStrategyResolver $strategy,
		\Broseph\Services\LandingPageService $landing_pages,
		\Broseph\Services\ReportBuilder $report_builder,
		\Broseph\Services\FormService $form_service,
		\Broseph\Services\DiviContactFormTestAdapter $divi_form_test,
		\Broseph\Services\MailLogService $mail_log,
		\Broseph\Services\MailTestService $mail_test,
		\Broseph\Services\UpdateService $update_service,
		\Broseph\Services\PermissionsService $permissions
	) {
		$this->signer         = $signer;
		$this->logger         = $logger;
		$this->scanner        = $scanner;
		$this->pages          = $pages;
		$this->gitpress       = $gitpress;
		$this->divi           = $divi;
		$this->strategy       = $strategy;
		$this->landing_pages  = $landing_pages;
		$this->report_builder = $report_builder;
		$this->form_service    = $form_service;
		$this->divi_form_test  = $divi_form_test;
		$this->mail_log        = $mail_log;
		$this->mail_test       = $mail_test;
		$this->update_service  = $update_service;
		$this->permissions     = $permissions;
	}

	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_all_routes' ) );
	}

	public function register_all_routes(): void {
		// Core routes handled here.
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		if ( defined( 'BROSEPH_DEV_MODE' ) && BROSEPH_DEV_MODE ) {
			register_rest_route(
				self::NAMESPACE,
				'/dev/echo',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_dev_echo' ),
					'permission_callback' => array( $this, 'require_signed' ),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/content/resolve-strategy',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_resolve_strategy' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		// Domain controllers.
		( new ScanController( $this->signer, $this->logger, $this->scanner ) )->register_routes( self::NAMESPACE );
		( new PagesController( $this->signer, $this->logger, $this->pages ) )->register_routes( self::NAMESPACE );
		( new GitPressController( $this->signer, $this->logger, $this->gitpress, $this->permissions ) )->register_routes( self::NAMESPACE );
		( new DiviController( $this->signer, $this->logger, $this->divi, $this->gitpress, $this->landing_pages, $this->permissions ) )->register_routes( self::NAMESPACE );
		( new LandingPagesController( $this->signer, $this->logger, $this->landing_pages ) )->register_routes( self::NAMESPACE );
		( new ReportsController( $this->signer, $this->logger, $this->report_builder ) )->register_routes( self::NAMESPACE );
		( new FormsController( $this->signer, $this->logger, $this->form_service, $this->divi_form_test, $this->mail_log, $this->mail_test, $this->permissions ) )->register_routes( self::NAMESPACE );
		( new MailController( $this->signer, $this->logger, $this->mail_test, $this->mail_log, $this->permissions ) )->register_routes( self::NAMESPACE );
		( new UpdatesController( $this->signer, $this->logger, $this->update_service ) )->register_routes( self::NAMESPACE );
		( new ToolsController( $this->signer, $this->logger, $this->gitpress, $this->divi, $this->permissions ) )->register_routes( self::NAMESPACE );
	}

	// Shared permission callback for routes registered directly on this class.
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

	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		$theme = wp_get_theme();

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'status_check',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		return new \WP_REST_Response(
			array(
				'site_url'       => site_url(),
				'home_url'       => home_url(),
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'active_theme'   => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
				'plugin_version' => BROSEPH_VERSION,
				'site_id'        => get_option( 'broseph_site_id' ),
				'current_time'   => current_time( 'c', true ),
				'auth_status'    => 'accepted',
			),
			200
		);
	}

	public function handle_dev_echo( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'dev_echo',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		return new \WP_REST_Response(
			array(
				'echoed_body'    => $request->get_json_params(),
				'echoed_headers' => $request->get_headers(),
				'route'          => $request->get_route(),
			),
			200
		);
	}

	public function handle_resolve_strategy( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$result = $this->strategy->resolve( $body );

		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'resolve_strategy',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
				'message'   => 'Strategy resolved: ' . ( $result['strategy'] ?? 'unknown' ),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}
}
