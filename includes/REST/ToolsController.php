<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolsController extends BaseController {

	private \Broseph\Services\GitPressIntegration $gitpress;
	private \Broseph\Services\DiviService $divi;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\GitPressIntegration $gitpress,
		\Broseph\Services\DiviService $divi
	) {
		parent::__construct( $signer, $logger );
		$this->gitpress = $gitpress;
		$this->divi     = $divi;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/tools',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_tools' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	public function handle_tools( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'get_tools',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		$gp_available = $this->gitpress->is_active() && $this->gitpress->is_shortcode_registered();
		$divi_active  = $this->divi->is_divi_active();

		return new \WP_REST_Response(
			array(
				'namespace' => 'broseph/v1',
				'base_url'  => rest_url( 'broseph/v1' ),
				'context'   => array(
					'gitpress_available' => $gp_available,
					'divi_active'        => $divi_active,
				),
				'tools'     => $this->build_manifest( $gp_available, $divi_active ),
			),
			200
		);
	}

	private function build_manifest( bool $gp, bool $divi ): array {
		return array(
			array(
				'name'              => 'get_status',
				'description'       => 'Check plugin health and verify authentication.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/status',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array(),
				'output_schema'     => array( 'site_url', 'wp_version', 'php_version', 'plugin_version', 'site_id', 'auth_status' ),
			),
			array(
				'name'              => 'run_scan',
				'description'       => 'Full site scan: plugins, themes, form/mail plugins, GitPress status, update availability.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/scan',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array(),
				'output_schema'     => array( 'active_plugins', 'is_divi_active', 'is_gitpress_active', 'available_plugin_updates' ),
			),
			array(
				'name'              => 'list_pages',
				'description'       => 'List WordPress pages with Divi/GitPress detection flags.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/pages',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'status', 'search', 'per_page', 'page' ),
				'output_schema'     => array( 'pages', 'total', 'total_pages' ),
			),
			array(
				'name'              => 'get_page',
				'description'       => 'Retrieve a single page including raw content and SEO meta.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/pages/{id}',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'id' ),
				'output_schema'     => array( 'id', 'title', 'content_raw', 'seo_meta', 'is_divi_page' ),
			),
			array(
				'name'              => 'duplicate_page',
				'description'       => 'Duplicate a page as a draft, optionally copying SEO meta.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/pages/duplicate',
				'risk_level'        => 'draft_mutation',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'source_page_id', 'new_title', 'new_slug', 'copy_meta' ),
				'output_schema'     => array( 'new_page_id', 'preview_url', 'edit_url' ),
			),
			array(
				'name'              => 'create_landing_page',
				'description'       => 'Create a landing page draft from a template in native, GitPress, or auto mode.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/landing-pages/create',
				'risk_level'        => 'draft_mutation',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'mode', 'template_page_id', 'title', 'slug', 'excerpt', 'replacements', 'meta', 'gitpress' ),
				'output_schema'     => array( 'status', 'strategy', 'page_id', 'preview_url', 'replaced_tokens', 'required_github_files', 'warnings' ),
			),
			array(
				'name'              => 'resolve_content_strategy',
				'description'       => 'Ask Broseph which content strategy to use for a landing page task.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/content/resolve-strategy',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'task_type', 'preferred_mode', 'page_id', 'template_page_id', 'risk_tolerance', 'requires_gitpress' ),
				'output_schema'     => array( 'strategy', 'reason', 'requires_github_files', 'requires_approval', 'warnings' ),
			),
			array(
				'name'              => 'get_gitpress_status',
				'description'       => 'Check whether GitPress is active and the shortcode is registered.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/gitpress/status',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => true,
				'requires_divi'     => false,
				'available'         => $gp,
				'input_schema'      => array(),
				'output_schema'     => array( 'is_active', 'shortcode_registered', 'shortcode_name', 'plugin_detected_name' ),
			),
			array(
				'name'              => 'scan_gitpress_usages',
				'description'       => 'Scan all pages for [divi_github_content] shortcode usages.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/gitpress/usages',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => true,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array(),
				'output_schema'     => array( 'usages', 'count' ),
			),
			array(
				'name'              => 'validate_gitpress_shortcode',
				'description'       => 'Validate a [divi_github_content] shortcode before using it.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/gitpress/validate-shortcode',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => true,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'shortcode' ),
				'output_schema'     => array( 'valid', 'errors' ),
			),
			array(
				'name'              => 'get_divi_page_summary',
				'description'       => 'Parse and summarize the Divi module structure of a page.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/divi/page/{id}/summary',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => true,
				'available'         => $divi,
				'input_schema'      => array( 'id' ),
				'output_schema'     => array( 'page_id', 'is_divi_page', 'module_count', 'modules', 'warnings' ),
			),
			array(
				'name'              => 'insert_gitpress_code_module',
				'description'       => 'Insert a [divi_github_content] shortcode into a Divi Code Module.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/divi/code-module/insert-gitpress',
				'risk_level'        => 'draft_mutation',
				'requires_approval' => false,
				'requires_gitpress' => true,
				'requires_divi'     => true,
				'available'         => $divi && $gp,
				'input_schema'      => array( 'page_id', 'shortcode', 'placement', 'save_mode' ),
				'output_schema'     => array( 'target_page_id', 'original_page_id', 'preview_url', 'inserted_shortcode', 'save_mode_used' ),
			),
			array(
				'name'              => 'generate_weekly_report',
				'description'       => 'Run the weekly site health report and store it.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/reports/weekly',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array(),
				'output_schema'     => array( 'status', 'summary', 'checks', 'recommendations', 'approval_required' ),
			),
			array(
				'name'              => 'list_forms',
				'description'       => 'Discover all contact forms on the site: CF7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, and Divi Contact Form modules.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/forms',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array(),
				'output_schema'     => array( 'forms', 'count' ),
			),
			array(
				'name'              => 'get_page_forms',
				'description'       => 'List all contact forms detected on a specific page by ID.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/forms/page/{id}',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'id' ),
				'output_schema'     => array( 'page_id', 'page_title', 'page_url', 'forms', 'count' ),
			),
			array(
				'name'              => 'test_form',
				'description'       => 'Check whether direct form submission testing is supported for a given form type. Returns unsupported in v1 — use send_mail_test for delivery verification.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/forms/test',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'form_type', 'page_id', 'form_id', 'test_recipient', 'test_payload' ),
				'output_schema'     => array( 'status', 'test_supported', 'message', 'next_steps', 'form_type', 'form_id' ),
			),
			array(
				'name'              => 'send_mail_test',
				'description'       => 'Send a real wp_mail() test through the site\'s configured WP Mail SMTP setup. Returns sent/failed with masked recipient.',
				'method'            => 'POST',
				'endpoint'          => '/broseph/v1/mail/test',
				'risk_level'        => 'mail_send',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'to', 'label', 'include_site_info' ),
				'output_schema'     => array( 'status', 'wp_mail_result', 'recipient_masked', 'recipient_domain', 'subject', 'timestamp', 'notes' ),
			),
			array(
				'name'              => 'get_recent_mail_logs',
				'description'       => 'Retrieve recent mail log entries from WP Mail SMTP, WP Mail Logging, or FluentSMTP. Returns supported: false if no logging plugin is active.',
				'method'            => 'GET',
				'endpoint'          => '/broseph/v1/mail/logs/recent',
				'risk_level'        => 'read_only',
				'requires_approval' => false,
				'requires_gitpress' => false,
				'requires_divi'     => false,
				'available'         => true,
				'input_schema'      => array( 'limit' ),
				'output_schema'     => array( 'supported', 'source_plugin', 'logs', 'message' ),
			),
		);
	}
}
