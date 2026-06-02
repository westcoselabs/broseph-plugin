<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ContentStrategyResolver {

	private GitPressIntegration $gitpress;
	private DiviService $divi;
	private PageService $pages;

	// ── Strategy metadata ─────────────────────────────────────────────────────
	//
	// Each strategy has a fixed endpoint, capability requirements, and approval
	// flag so Open Claw can understand exactly what it needs before calling the
	// recommended endpoint.
	//
	// Strategy guide:
	//   gitpress_canvas          - Default for new AI pages. GitPress page-level
	//                              shortcode, no Divi required.
	//   divi_template            - Clone a Divi template page.
	//   template_gitpress        - Clone Divi template + inject GitPress blocks.
	//   template_native          - Legacy alias for divi_template.
	//   existing_divi_code_module - Add content to an existing Divi page.
	//   existing_divi_injection  - Legacy alias for existing_divi_code_module.
	//   native_draft             - Plain WordPress draft, no builder.
	//   proposal_only            - Return plan only; human approval needed.
	//   unsupported              - No viable strategy found.

	private const STRATEGY_META = array(
		'gitpress_canvas' => array(
			'endpoint'               => '/broseph/v1/gitpress/pages/create',
			'requires_github_files'  => true,
			'requires_divi'          => false,
			'requires_gitpress'      => true,
			'requires_existing_page' => false,
			'requires_template_page' => false,
			'requires_approval'      => false,
		),
		'divi_template' => array(
			'endpoint'               => '/broseph/v1/divi/pages/create-from-template',
			'requires_github_files'  => false,
			'requires_divi'          => false,
			'requires_gitpress'      => false,
			'requires_existing_page' => false,
			'requires_template_page' => true,
			'requires_approval'      => false,
		),
		'template_gitpress' => array(
			'endpoint'               => '/broseph/v1/landing-pages/create',
			'requires_github_files'  => true,
			'requires_divi'          => false,
			'requires_gitpress'      => true,
			'requires_existing_page' => false,
			'requires_template_page' => true,
			'requires_approval'      => false,
		),
		'template_native' => array(
			'endpoint'               => '/broseph/v1/landing-pages/create',
			'requires_github_files'  => false,
			'requires_divi'          => false,
			'requires_gitpress'      => false,
			'requires_existing_page' => false,
			'requires_template_page' => true,
			'requires_approval'      => false,
		),
		'existing_divi_code_module' => array(
			'endpoint'               => '/broseph/v1/divi/code-module/insert-gitpress',
			'requires_github_files'  => false,
			'requires_divi'          => true,
			'requires_gitpress'      => true,
			'requires_existing_page' => true,
			'requires_template_page' => false,
			'requires_approval'      => false,
		),
		'existing_divi_injection' => array(
			'endpoint'               => '/broseph/v1/divi/code-module/insert-gitpress',
			'requires_github_files'  => false,
			'requires_divi'          => true,
			'requires_gitpress'      => true,
			'requires_existing_page' => true,
			'requires_template_page' => false,
			'requires_approval'      => false,
		),
		'native_draft' => array(
			'endpoint'               => '/broseph/v1/pages/create-draft',
			'requires_github_files'  => false,
			'requires_divi'          => false,
			'requires_gitpress'      => false,
			'requires_existing_page' => false,
			'requires_template_page' => false,
			'requires_approval'      => false,
		),
		'proposal_only' => array(
			'endpoint'               => null,
			'requires_github_files'  => false,
			'requires_divi'          => false,
			'requires_gitpress'      => false,
			'requires_existing_page' => false,
			'requires_template_page' => false,
			'requires_approval'      => true,
		),
		'unsupported' => array(
			'endpoint'               => null,
			'requires_github_files'  => false,
			'requires_divi'          => false,
			'requires_gitpress'      => false,
			'requires_existing_page' => false,
			'requires_template_page' => false,
			'requires_approval'      => true,
		),
	);

	public function __construct(
		GitPressIntegration $gitpress,
		DiviService $divi,
		PageService $pages
	) {
		$this->gitpress = $gitpress;
		$this->divi     = $divi;
		$this->pages    = $pages;
	}

	public function resolve( array $params ): array {
		$task_type         = (string) ( $params['task_type'] ?? 'create_landing_page' );
		$preferred_mode    = (string) ( $params['preferred_mode'] ?? 'auto' );
		$page_id           = isset( $params['page_id'] ) ? (int) $params['page_id'] : null;
		$template_id       = isset( $params['template_page_id'] ) ? (int) $params['template_page_id'] : null;
		$risk              = (string) ( $params['risk_tolerance'] ?? 'low' );
		$needs_gitpress    = (bool) ( $params['requires_gitpress'] ?? false );
		$needs_divi_layout = (bool) ( $params['requires_divi_layout'] ?? false );

		$warnings = array();

		$gitpress_available = $this->gitpress->is_active() && $this->gitpress->is_shortcode_registered();
		$divi_available     = $this->divi->is_divi_active();

		// Explicit preferred_mode bypasses auto logic.
		if ( 'auto' !== $preferred_mode ) {
			return $this->explicit_mode( $preferred_mode, $gitpress_available, $divi_available, $warnings );
		}

		// ── Auto resolution ───────────────────────────────────────────────────

		// Rule 4: existing Divi page → Divi Code Module insertion.
		if ( null !== $page_id ) {
			$page = get_post( $page_id );
			if ( $page && $this->divi->is_divi_page( $page->post_content ) ) {
				if ( 'high' === $risk ) {
					$warnings[] = 'Divi code module insertion skipped; risk_tolerance is high. Returning proposal_only.';
					return $this->result( 'proposal_only', 'Risk tolerance is high; returning proposal only.', $warnings );
				}
				return $this->result(
					'existing_divi_code_module',
					'Target page is a Divi page — use Divi Code Module insertion to add GitPress content.',
					$warnings
				);
			}
			$warnings[] = 'page_id provided but the page is not a Divi page; ignoring page_id and using creation strategies.';
		}

		// Rule 3 (explicit): requires_divi_layout + template_page_id → divi_template.
		if ( $needs_divi_layout && null !== $template_id ) {
			return $this->result(
				'divi_template',
				'requires_divi_layout is true with template_page_id provided; cloning Divi layout.',
				$warnings
			);
		}

		if ( $needs_divi_layout && null === $template_id ) {
			$warnings[] = 'requires_divi_layout is true but no template_page_id was provided; falling through to creation strategies.';
		}

		// Rule 1: create task + no page_id + GitPress available → gitpress_canvas.
		$is_create_task = in_array( $task_type, array( 'create_page', 'create_landing_page' ), true );
		if ( $is_create_task && null === $page_id && $gitpress_available ) {
			return $this->result(
				'gitpress_canvas',
				'GitPress is active — gitpress_canvas is the recommended default for new AI-generated landing pages. No Divi Builder required.',
				$warnings
			);
		}

		// Rule 5/3: template_page_id provided → divi_template.
		if ( null !== $template_id ) {
			if ( ! $divi_available ) {
				$warnings[] = 'Divi may not be active; proceeding with divi_template since template_page_id was provided.';
			}
			return $this->result(
				'divi_template',
				'template_page_id provided; cloning the Divi layout into a new draft.',
				$warnings
			);
		}

		// Rule 6: GitPress required but unavailable.
		if ( $needs_gitpress && ! $gitpress_available ) {
			$warnings[] = 'requires_gitpress is true but GitPress is not active or shortcode not registered.';
			return $this->result( 'unsupported', 'GitPress is required but not available.', $warnings );
		}

		// Rule 6: Nothing available — plain draft.
		if ( ! $gitpress_available && null === $template_id ) {
			$warnings[] = 'GitPress not available and no template_page_id provided; falling back to native_draft.';
		}
		return $this->result(
			'native_draft',
			'No GitPress or Divi template available; creating a plain draft page.',
			$warnings
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private function explicit_mode( string $mode, bool $gitpress, bool $divi, array $warnings ): array {
		switch ( $mode ) {
			// Rule 2: gitpress_canvas only if GitPress is available.
			case 'gitpress_canvas':
				if ( ! $gitpress ) {
					return $this->result(
						'unsupported',
						'gitpress_canvas requested but GitPress is not active or shortcode not registered.',
						$warnings
					);
				}
				return $this->result( 'gitpress_canvas', 'Explicit gitpress_canvas mode honoured.', $warnings );

			case 'divi_template':
			case 'template_native':
				if ( ! $divi ) {
					$warnings[] = 'Divi may not be active; proceeding with divi_template anyway.';
				}
				return $this->result( 'divi_template', 'Explicit divi_template mode honoured.', $warnings );

			case 'template_gitpress':
				if ( ! $gitpress ) {
					return $this->result(
						'unsupported',
						'template_gitpress requested but GitPress is not active.',
						$warnings
					);
				}
				return $this->result( 'template_gitpress', 'Explicit template_gitpress mode honoured.', $warnings );

			case 'existing_divi_code_module':
			case 'existing_divi_injection':
				return $this->result(
					'existing_divi_code_module',
					'Explicit existing_divi_code_module mode honoured.',
					$warnings
				);

			case 'native_draft':
				return $this->result( 'native_draft', 'Explicit native_draft mode honoured.', $warnings );

			case 'proposal_only':
				return $this->result( 'proposal_only', 'Explicit proposal_only mode honoured.', $warnings );

			default:
				return $this->result( 'unsupported', "Unknown preferred_mode: {$mode}.", $warnings );
		}
	}

	private function result( string $strategy, string $reason, array $warnings ): array {
		$meta = self::STRATEGY_META[ $strategy ] ?? self::STRATEGY_META['unsupported'];

		return array(
			'strategy'               => $strategy,
			'endpoint'               => $meta['endpoint'],
			'reason'                 => $reason,
			'requires_github_files'  => $meta['requires_github_files'],
			'requires_divi'          => $meta['requires_divi'],
			'requires_gitpress'      => $meta['requires_gitpress'],
			'requires_existing_page' => $meta['requires_existing_page'],
			'requires_template_page' => $meta['requires_template_page'],
			'requires_approval'      => $meta['requires_approval'],
			'warnings'               => $warnings,
		);
	}
}
