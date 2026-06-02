<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates landing pages in three modes:
 *   template_native   — duplicate a template page, replace placeholders
 *   template_gitpress — same, but placeholders become [divi_github_content] shortcodes
 *   auto              — let ContentStrategyResolver choose, then delegate
 */
class LandingPageService {

	private const SEO_META = array(
		'yoast_title'           => '_yoast_wpseo_title',
		'yoast_description'     => '_yoast_wpseo_metadesc',
		'rank_math_title'       => 'rank_math_title',
		'rank_math_description' => 'rank_math_description',
	);

	private const SAFE_MODES = array( 'template_native', 'template_gitpress', 'auto' );

	private const DIVI_META = array(
		'_et_pb_use_builder',
		'_et_pb_old_content',
		'_et_pb_page_layout',
		'_et_pb_side_nav',
		'_et_pb_post_hide_nav',
		'_et_pb_show_title',
		'_et_builder_version',
		'_et_pb_built_for_post_type',
		'_et_pb_custom_css',
		'_et_pb_light_text_color',
		'_et_pb_dark_text_color',
		'_et_pb_content_area_background_color',
		'_et_pb_section_background_color',
	);

	private GitPressIntegration $gitpress;
	private ContentStrategyResolver $resolver;
	private DiviService $divi;

	public function __construct(
		GitPressIntegration $gitpress,
		ContentStrategyResolver $resolver,
		DiviService $divi
	) {
		$this->gitpress = $gitpress;
		$this->resolver = $resolver;
		$this->divi     = $divi;
	}

	// ── Divi template page creation ──────────────────────────────────────────

	public function create_from_divi_template( array $params ): array|\WP_Error {
		$template_id = isset( $params['template_page_id'] ) ? (int) $params['template_page_id'] : 0;
		if ( $template_id <= 0 ) {
			return new \WP_Error( 'broseph_bad_request', 'template_page_id is required.', array( 'status' => 400 ) );
		}

		$template = get_post( $template_id );
		if ( ! $template || 'page' !== $template->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Template page not found.', array( 'status' => 404 ) );
		}

		$title        = sanitize_text_field( $params['title'] ?? '' ) ?: ( $template->post_title . ' (Copy)' );
		$slug         = sanitize_title( $params['slug'] ?? $title );
		$excerpt      = wp_kses_post( $params['excerpt'] ?? '' );
		$replacements = is_array( $params['replacements'] ?? null ) ? $params['replacements'] : array();
		$meta         = is_array( $params['meta'] ?? null ) ? $params['meta'] : array();
		$code_modules = is_array( $params['code_modules'] ?? null ) ? $params['code_modules'] : array();

		// Apply placeholder replacements.
		[ $content, $replaced_tokens, $rep_warnings ] = $this->apply_replacements( $template->post_content, $replacements );

		// Process code modules — v1 supports append mode only.
		$cm_warnings       = array();
		$inserted_cm_count = 0;

		foreach ( $code_modules as $cm ) {
			if ( ! is_array( $cm ) ) {
				$cm_warnings[] = 'Skipped non-array code_modules entry.';
				continue;
			}

			$mode       = sanitize_text_field( (string) ( $cm['mode'] ?? 'append' ) );
			$cm_content = (string) ( $cm['content'] ?? '' );

			if ( 'append' !== $mode ) {
				$cm_warnings[] = "Code module mode '{$mode}' is not supported in v1. Only 'append' is supported.";
				continue;
			}

			if ( '' === $cm_content ) {
				$cm_warnings[] = 'Skipped empty code_module content.';
				continue;
			}

			$validation = $this->divi->validate_code_module_content( $cm_content );
			if ( ! $validation['valid'] ) {
				foreach ( $validation['errors'] as $err ) {
					$cm_warnings[] = 'Code module rejected: ' . $err;
				}
				continue;
			}

			foreach ( $validation['warnings'] as $w ) {
				$cm_warnings[] = $w;
			}

			$content = $this->divi->append_code_module( $content, $cm_content );
			$inserted_cm_count++;
		}

		$all_warnings = array_merge( $rep_warnings, $cm_warnings );

		// Insert new draft with final content.
		$new_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_excerpt' => $excerpt ?: $template->post_excerpt,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy page template + Divi builder meta from source.
		$this->copy_page_setup( $template->ID, $new_id, $content );

		// Update SEO meta.
		$this->update_seo_meta( $new_id, $meta );

		return array(
			'status'                => 'draft_created',
			'page_id'               => $new_id,
			'preview_url'           => get_preview_post_link( $new_id ),
			'edit_url'              => admin_url( 'post.php?post=' . $new_id . '&action=edit' ),
			'divi_builder_active'   => 'on' === get_post_meta( $new_id, '_et_pb_use_builder', true ),
			'inserted_code_modules' => $inserted_cm_count,
			'replaced_tokens'       => $replaced_tokens,
			'warnings'              => $all_warnings,
		);
	}

	// ── Existing modes ────────────────────────────────────────────────────────

	public function create( array $params ): array|\WP_Error {
		$mode = (string) ( $params['mode'] ?? 'template_native' );

		if ( ! in_array( $mode, self::SAFE_MODES, true ) ) {
			return new \WP_Error(
				'broseph_unsupported_mode',
				"Mode '{$mode}' is not supported. Allowed: " . implode( ', ', self::SAFE_MODES ),
				array( 'status' => 422 )
			);
		}

		return match ( $mode ) {
			'template_native'  => $this->create_native( $params ),
			'template_gitpress' => $this->create_gitpress( $params ),
			'auto'             => $this->create_auto( $params ),
		};
	}

	// ── Phase 11: template_native ─────────────────────────────────────────

	private function create_native( array $params ): array|\WP_Error {
		$validated = $this->validate_template_params( $params );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		[ $template, $title, $slug, $excerpt, $replacements, $meta ] = $validated;

		[ $new_content, $replaced_tokens, $warnings ] = $this->apply_replacements(
			$template->post_content,
			$replacements
		);

		$new_id = $this->insert_draft( $template, $title, $slug, $excerpt, $new_content );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$this->copy_page_setup( $template->ID, $new_id, $new_content );
		$this->update_seo_meta( $new_id, $meta );

		return array(
			'status'          => 'draft_created',
			'strategy'        => 'template_native',
			'page_id'         => $new_id,
			'preview_url'     => get_preview_post_link( $new_id ),
			'replaced_tokens' => $replaced_tokens,
			'warnings'        => $warnings,
		);
	}

	// ── Phase 12: template_gitpress ───────────────────────────────────────

	private function create_gitpress( array $params ): array|\WP_Error {
		if ( ! $this->gitpress->is_active() ) {
			return new \WP_Error(
				'broseph_gitpress_inactive',
				'GitPress plugin is not active on this site.',
				array( 'status' => 422 )
			);
		}

		if ( ! $this->gitpress->is_shortcode_registered() ) {
			return new \WP_Error(
				'broseph_gitpress_no_shortcode',
				'GitPress shortcode is not registered.',
				array( 'status' => 422 )
			);
		}

		$validated = $this->validate_template_params( $params );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		[ $template, $title, $slug, $excerpt, , $meta ] = $validated;

		$gp_config = is_array( $params['gitpress'] ?? null ) ? $params['gitpress'] : array();
		$blocks    = is_array( $gp_config['blocks'] ?? null ) ? $gp_config['blocks'] : array();
		$owner     = sanitize_text_field( (string) ( $gp_config['owner'] ?? '' ) );
		$repo      = sanitize_text_field( (string) ( $gp_config['repo'] ?? '' ) );
		$base_path = trim( sanitize_text_field( (string) ( $gp_config['base_path'] ?? '' ) ), '/' );

		if ( ! $owner || ! $repo ) {
			return new \WP_Error(
				'broseph_bad_request',
				'gitpress.owner and gitpress.repo are required for template_gitpress mode.',
				array( 'status' => 400 )
			);
		}

		$content            = $template->post_content;
		$required_files     = array();
		$inserted_shortcodes = array();
		$warnings           = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$warnings[] = 'Skipped non-array block.';
				continue;
			}

			$name        = sanitize_text_field( (string) ( $block['name'] ?? '' ) );
			$path_suffix = sanitize_text_field( (string) ( $block['path'] ?? '' ) );
			$format      = sanitize_text_field( (string) ( $block['format'] ?? 'html' ) );
			$placeholder = (string) ( $block['placeholder'] ?? '' );

			// Fix 4: whitelist format before building the shortcode.
			$allowed_formats = array( 'html', 'markdown', 'text', 'code', 'raw' );
			if ( ! in_array( $format, $allowed_formats, true ) ) {
				$warnings[] = "Block '{$name}' has invalid format '{$format}'; allowed: "
					. implode( ', ', $allowed_formats ) . '. Block skipped.';
				continue;
			}

			$full_path = $base_path ? "{$base_path}/{$path_suffix}" : $path_suffix;

			// Fix 3: esc_attr() on every shortcode attribute value.
			$shortcode = '[divi_github_content owner="' . esc_attr( $owner )
				. '" repo="' . esc_attr( $repo )
				. '" path="' . esc_attr( $full_path )
				. '" format="' . esc_attr( $format ) . '"]';

			$validation = $this->gitpress->validate_shortcode( $shortcode );
			if ( ! $validation['valid'] ) {
				$warnings[] = "Block '{$name}' shortcode invalid: " . implode( ' ', $validation['errors'] );
				$inserted_shortcodes[] = array( 'name' => $name, 'shortcode' => $shortcode, 'placeholder_found' => false, 'valid' => false );
				continue;
			}

			$required_files[] = $full_path;

			if ( $placeholder && str_contains( $content, $placeholder ) ) {
				$content = str_replace( $placeholder, $shortcode, $content );
				$inserted_shortcodes[] = array( 'name' => $name, 'shortcode' => $shortcode, 'placeholder_found' => true, 'valid' => true );
			} else {
				$warnings[]            = "Placeholder '{$placeholder}' for block '{$name}' not found in template content; shortcode not inserted.";
				$inserted_shortcodes[] = array( 'name' => $name, 'shortcode' => $shortcode, 'placeholder_found' => false, 'valid' => true );
			}
		}

		$new_id = $this->insert_draft( $template, $title, $slug, $excerpt, $content );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$this->copy_page_setup( $template->ID, $new_id, $content );
		$this->update_seo_meta( $new_id, $meta );

		return array(
			'status'              => 'draft_created',
			'strategy'            => 'template_gitpress',
			'page_id'             => $new_id,
			'preview_url'         => get_preview_post_link( $new_id ),
			'required_github_files' => $required_files,
			'inserted_shortcodes' => $inserted_shortcodes,
			'warnings'            => $warnings,
		);
	}

	// ── Phase 13: auto ───────────────────────────────────────────────────

	private function create_auto( array $params ): array|\WP_Error {
		$page_id     = isset( $params['page_id'] ) ? (int) $params['page_id'] : null;
		$template_id = isset( $params['template_page_id'] ) ? (int) $params['template_page_id'] : null;

		$resolved = $this->resolver->resolve(
			array(
				'task_type'        => 'create_landing_page',
				'preferred_mode'   => 'auto',
				'page_id'          => $page_id,
				'template_page_id' => $template_id,
				'risk_tolerance'   => $params['risk_tolerance'] ?? 'low',
				'requires_gitpress' => ! empty( $params['gitpress'] ) || ! empty( $params['gitpress_shortcode'] ),
			)
		);

		$strategy = $resolved['strategy'];

		switch ( $strategy ) {
			// existing_divi_code_module is the new name; existing_divi_injection kept for compat.
			case 'existing_divi_code_module':
			case 'existing_divi_injection':
				$result = $this->handle_divi_injection( $params );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$result['auto_strategy']        = $strategy;
				$result['auto_strategy_reason'] = $resolved['reason'];
				return $result;

			case 'template_gitpress':
				$result = $this->create_gitpress( $params );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$result['auto_strategy_reason'] = $resolved['reason'];
				return $result;

			// divi_template is the new name for template_native.
			case 'divi_template':
			case 'template_native':
				$result = $this->create_native( $params );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$result['auto_strategy_reason'] = $resolved['reason'];
				return $result;

			case 'proposal_only':
				return array(
					'status'               => 'proposal_only',
					'strategy'             => 'proposal_only',
					'auto_strategy_reason' => $resolved['reason'],
					'warnings'             => $resolved['warnings'],
					'requires_approval'    => true,
				);

			// gitpress_canvas and native_draft require dedicated endpoints —
			// return a redirect hint so Open Claw uses the correct route.
			case 'gitpress_canvas':
				return array(
					'status'               => 'redirect',
					'strategy'             => 'gitpress_canvas',
					'endpoint'             => '/broseph/v1/gitpress/pages/create',
					'auto_strategy_reason' => $resolved['reason'],
					'message'              => 'Use POST /broseph/v1/gitpress/pages/create for GitPress Canvas pages.',
					'warnings'             => $resolved['warnings'],
					'requires_approval'    => false,
				);

			case 'native_draft':
				return array(
					'status'               => 'redirect',
					'strategy'             => 'native_draft',
					'endpoint'             => '/broseph/v1/pages/create-draft',
					'auto_strategy_reason' => $resolved['reason'],
					'message'              => 'Use POST /broseph/v1/pages/create-draft for a plain draft page.',
					'warnings'             => $resolved['warnings'],
					'requires_approval'    => false,
				);

			default:
				return new \WP_Error(
					'broseph_unsupported',
					'No safe strategy available: ' . $resolved['reason'],
					array( 'status' => 422 )
				);
		}
	}

	private function handle_divi_injection( array $params ): array|\WP_Error {
		$page_id   = isset( $params['page_id'] ) ? (int) $params['page_id'] : 0;
		$shortcode = trim( (string) ( $params['gitpress_shortcode'] ?? '' ) );
		$placement = is_array( $params['placement'] ?? null ) ? $params['placement'] : array( 'mode' => 'append_to_page' );
		$save_mode = (string) ( $params['save_mode'] ?? 'draft_copy' );

		if ( $page_id <= 0 ) {
			return new \WP_Error( 'broseph_bad_request', 'page_id required for existing_divi_injection.', array( 'status' => 400 ) );
		}
		if ( empty( $shortcode ) ) {
			return new \WP_Error( 'broseph_bad_request', 'gitpress_shortcode required for existing_divi_injection.', array( 'status' => 400 ) );
		}

		$validation = $this->gitpress->validate_shortcode( $shortcode );
		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'broseph_invalid_shortcode',
				'Shortcode invalid: ' . implode( ' ', $validation['errors'] ),
				array( 'status' => 422 )
			);
		}

		$allow_live = (bool) get_option( 'broseph_allow_live_edits', '0' );
		if ( 'live_edit' === $save_mode && ! $allow_live ) {
			return new \WP_Error( 'broseph_live_edits_disabled', 'live_edit is disabled in settings.', array( 'status' => 403 ) );
		}

		$source = get_post( $page_id );
		if ( ! $source || 'page' !== $source->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$placement_mode = (string) ( $placement['mode'] ?? 'append_to_page' );
		$new_content    = $this->build_divi_content( $source->post_content, $shortcode, $placement_mode, $placement );
		if ( is_wp_error( $new_content ) ) {
			return $new_content;
		}

		$target_id = $this->apply_save_mode( $source, $new_content, $save_mode );
		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}

		return array(
			'status'             => 'draft_created',
			'target_page_id'     => $target_id,
			'original_page_id'   => $page_id,
			'preview_url'        => get_preview_post_link( $target_id ),
			'inserted_shortcode' => $shortcode,
			'save_mode_used'     => $save_mode,
		);
	}

	// ── Shared helpers ────────────────────────────────────────────────────

	private function validate_template_params( array $params ): array|\WP_Error {
		$template_id = isset( $params['template_page_id'] ) ? (int) $params['template_page_id'] : 0;
		$title       = sanitize_text_field( $params['title'] ?? '' );
		$slug        = sanitize_title( $params['slug'] ?? $title );
		$excerpt     = wp_kses_post( $params['excerpt'] ?? '' );
		$replacements = is_array( $params['replacements'] ?? null ) ? $params['replacements'] : array();
		$meta        = is_array( $params['meta'] ?? null ) ? $params['meta'] : array();

		if ( $template_id <= 0 ) {
			return new \WP_Error( 'broseph_bad_request', 'template_page_id is required.', array( 'status' => 400 ) );
		}
		if ( empty( $title ) ) {
			return new \WP_Error( 'broseph_bad_request', 'title is required.', array( 'status' => 400 ) );
		}

		$template = get_post( $template_id );
		if ( ! $template || 'page' !== $template->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Template page not found.', array( 'status' => 404 ) );
		}

		return array( $template, $title, $slug, $excerpt, $replacements, $meta );
	}

	private function apply_replacements( string $content, array $replacements ): array {
		$replaced_tokens = array();
		$warnings        = array();

		foreach ( $replacements as $placeholder => $value ) {
			$placeholder = str_replace( "\0", '', (string) $placeholder );
			$value       = (string) $value;

			if ( empty( $placeholder ) ) {
				$warnings[] = 'Empty placeholder key skipped.';
				continue;
			}

			if ( strlen( $placeholder ) > 200 ) {
				$warnings[] = 'Placeholder key too long (>200 chars), skipped: ' . mb_substr( $placeholder, 0, 40 ) . '…';
				continue;
			}

			if ( str_contains( $content, $placeholder ) ) {
				$content           = str_replace( $placeholder, $value, $content );
				$replaced_tokens[] = $placeholder;
			} else {
				$warnings[] = "Placeholder not found in template content: {$placeholder}";
			}
		}

		return array( $content, $replaced_tokens, $warnings );
	}

	private function update_seo_meta( int $page_id, array $meta ): void {
		$title = isset( $meta['title'] ) ? sanitize_text_field( (string) $meta['title'] ) : null;
		$desc  = isset( $meta['description'] ) ? sanitize_text_field( (string) $meta['description'] ) : null;

		if ( null !== $title ) {
			update_post_meta( $page_id, '_yoast_wpseo_title', $title );
			update_post_meta( $page_id, 'rank_math_title', $title );
		}
		if ( null !== $desc ) {
			update_post_meta( $page_id, '_yoast_wpseo_metadesc', $desc );
			update_post_meta( $page_id, 'rank_math_description', $desc );
		}
	}

	private function copy_page_setup( int $source_id, int $new_id, string $new_content = '' ): void {
		$template = get_post_meta( $source_id, '_wp_page_template', true );
		if ( $template ) {
			update_post_meta( $new_id, '_wp_page_template', $template );
		}
		$this->copy_builder_meta( $source_id, $new_id, $new_content );
	}

	private function copy_builder_meta( int $source_id, int $target_id, string $source_content = '' ): void {
		foreach ( self::DIVI_META as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' !== $value ) {
				update_post_meta( $target_id, $key, $value );
			}
		}

		$content = $source_content ?: (string) get_post_field( 'post_content', $source_id );
		if ( str_contains( $content, '[et_pb_section' ) ) {
			update_post_meta( $target_id, '_et_pb_use_builder', 'on' );
			update_post_meta( $target_id, '_et_pb_built_for_post_type', 'page' );
		}
	}

	private function insert_draft( \WP_Post $template, string $title, string $slug, string $excerpt, string $content ): int|\WP_Error {
		return wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_excerpt' => $excerpt ?: $template->post_excerpt,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);
	}

	private function build_divi_content( string $content, string $shortcode, string $mode, array $placement ): string|\WP_Error {
		if ( 'append_to_page' === $mode ) {
			return $this->divi->append_code_module( $content, $shortcode );
		}
		if ( 'after_module_index' === $mode ) {
			$idx    = isset( $placement['module_index'] ) ? (int) $placement['module_index'] : -1;
			if ( $idx < 0 ) {
				return new \WP_Error( 'broseph_bad_request', 'placement.module_index required.', array( 'status' => 400 ) );
			}
			$result = $this->divi->insert_code_module_after( $content, $idx, $shortcode );
			if ( null === $result ) {
				return new \WP_Error( 'broseph_parse_unsafe', 'Cannot safely determine insertion position. Use append_to_page.', array( 'status' => 422 ) );
			}
			return $result;
		}
		return new \WP_Error( 'broseph_bad_request', "Unsupported placement mode: {$mode}", array( 'status' => 400 ) );
	}

	private function apply_save_mode( \WP_Post $source, string $new_content, string $save_mode ): int|\WP_Error {
		if ( 'live_edit' === $save_mode ) {
			$result = wp_update_post( array( 'ID' => $source->ID, 'post_content' => $new_content ), true );
			return is_wp_error( $result ) ? $result : $source->ID;
		}

		if ( 'update_draft_only' === $save_mode ) {
			if ( 'draft' !== $source->post_status ) {
				return new \WP_Error( 'broseph_not_draft', 'update_draft_only requires the target to be a draft.', array( 'status' => 422 ) );
			}
			$result = wp_update_post( array( 'ID' => $source->ID, 'post_content' => $new_content ), true );
			return is_wp_error( $result ) ? $result : $source->ID;
		}

		// draft_copy (default)
		return wp_insert_post(
			array(
				'post_title'   => $source->post_title . ' (Broseph Draft)',
				'post_name'    => $source->post_name . '-broseph-draft',
				'post_content' => $new_content,
				'post_excerpt' => $source->post_excerpt,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);
	}
}
