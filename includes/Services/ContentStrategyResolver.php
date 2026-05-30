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
		$task_type       = $params['task_type'] ?? 'create_landing_page';
		$preferred_mode  = $params['preferred_mode'] ?? 'auto';
		$page_id         = isset( $params['page_id'] ) ? (int) $params['page_id'] : null;
		$template_id     = isset( $params['template_page_id'] ) ? (int) $params['template_page_id'] : null;
		$risk            = $params['risk_tolerance'] ?? 'low';
		$needs_gitpress  = (bool) ( $params['requires_gitpress'] ?? false );

		$warnings = array();

		$gitpress_available = $this->gitpress->is_active() && $this->gitpress->is_shortcode_registered();
		$divi_available     = $this->divi->is_divi_active();
		$prefer_gitpress    = (bool) get_option( 'broseph_prefer_gitpress_landing_pages', '1' );

		// Explicit mode requested — validate and honour if possible.
		if ( 'auto' !== $preferred_mode ) {
			return $this->explicit_mode( $preferred_mode, $gitpress_available, $divi_available, $warnings );
		}

		// Existing Divi page injection.
		if ( null !== $page_id ) {
			$page = get_post( $page_id );
			if ( $page && $this->divi->is_divi_page( $page->post_content ) ) {
				if ( 'high' === $risk ) {
					$warnings[] = 'Divi injection skipped due to high risk tolerance setting; defaulting to proposal_only.';
					return $this->result( 'proposal_only', 'Risk tolerance is high; returning proposal only.', false, true, $warnings );
				}
				return $this->result( 'existing_divi_injection', 'Target page is a Divi page; injection strategy chosen.', false, false, $warnings );
			}
			$warnings[] = 'page_id provided but page is not a Divi page; falling through to creation strategies.';
		}

		// New landing page creation.
		if ( $gitpress_available && $prefer_gitpress ) {
			return $this->result( 'template_gitpress', 'GitPress is active and preferred; using GitPress landing page template.', true, false, $warnings );
		}

		if ( ! $gitpress_available && $needs_gitpress ) {
			$warnings[] = 'requires_gitpress is true but GitPress is not active.';
			return $this->result( 'unsupported', 'GitPress is required but not available.', false, true, $warnings );
		}

		if ( $divi_available || null !== $template_id ) {
			return $this->result( 'template_native', 'Using native Divi template; GitPress unavailable or not preferred.', false, false, $warnings );
		}

		return $this->result( 'unsupported', 'No safe strategy found for the given parameters.', false, true, $warnings );
	}

	private function explicit_mode( string $mode, bool $gitpress, bool $divi, array $warnings ): array {
		switch ( $mode ) {
			case 'template_gitpress':
				if ( ! $gitpress ) {
					return $this->result( 'unsupported', 'template_gitpress requested but GitPress is not active.', false, true, $warnings );
				}
				return $this->result( 'template_gitpress', 'Explicit template_gitpress mode honoured.', true, false, $warnings );

			case 'template_native':
				if ( ! $divi ) {
					$warnings[] = 'Divi may not be active; proceeding with template_native anyway.';
				}
				return $this->result( 'template_native', 'Explicit template_native mode honoured.', false, false, $warnings );

			case 'existing_divi_injection':
				return $this->result( 'existing_divi_injection', 'Explicit existing_divi_injection mode honoured.', false, false, $warnings );

			case 'proposal_only':
				return $this->result( 'proposal_only', 'Explicit proposal_only mode honoured.', false, true, $warnings );

			default:
				return $this->result( 'unsupported', "Unknown preferred_mode: {$mode}.", false, true, $warnings );
		}
	}

	private function result( string $strategy, string $reason, bool $needs_github, bool $needs_approval, array $warnings ): array {
		return array(
			'strategy'              => $strategy,
			'reason'                => $reason,
			'requires_github_files' => $needs_github,
			'requires_approval'     => $needs_approval,
			'warnings'              => $warnings,
		);
	}
}
