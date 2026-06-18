<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitPressIntegration {

	private const SHORTCODE     = 'divi_github_content';
	private const VALID_FORMATS = array( 'html', 'markdown', 'text', 'code', 'raw' );

	// Slug fragments used to identify the GitPress / Divi GitHub Sync plugin file.
	private const PLUGIN_SLUGS = array( 'gitpress', 'divi-github-sync', 'divi_github' );

	// Meta keys set by the Divi GitHub Sync "GitHub Shortcode" metabox.
	// These are read from class-page-shortcode-manager.php constants:
	//   DGS_Page_Shortcode_Manager::META_KEY_SHORTCODE  = '_dgs_page_shortcode'
	//   DGS_Page_Shortcode_Manager::META_KEY_PLACEMENT  = '_dgs_page_shortcode_placement'
	//   DGS_Page_Shortcode_Manager::META_KEY_FULL_PAGE  = '_dgs_page_shortcode_full_page'
	private const DGS_META_SHORTCODE = '_dgs_page_shortcode';
	private const DGS_META_PLACEMENT = '_dgs_page_shortcode_placement';
	private const DGS_META_FULL_PAGE = '_dgs_page_shortcode_full_page';
	private const DGS_META_RENDER_MODE = '_dgs_page_shortcode_render_mode';
	private const DGS_META_FULL_WIDTH = '_dgs_page_shortcode_full_width';

	// Valid placement values accepted by the DGS plugin.
	private const VALID_PLACEMENTS = array( 'before', 'after', 'replace' );
	private const VALID_RENDER_MODES = array( 'theme_wrapped', 'full_canvas', 'gitpress_managed' );

	// Options storing the global GitPress Managed header/footer shortcodes.
	private const OPT_MANAGED_HEADER = 'dgs_managed_header_shortcode';
	private const OPT_MANAGED_FOOTER = 'dgs_managed_footer_shortcode';

	// Shortcode tags allowed in the GitPress Managed global header/footer.
	private const LAYOUT_VALID_SHORTCODES = array( 'divi_github', 'divi_github_content' );

	private const BACKUP_BLOCKED_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_pingme', '_encloseme' );

	public function is_active(): bool {
		return null !== $this->get_detected_plugin_file();
	}

	public function is_shortcode_registered(): bool {
		return shortcode_exists( self::SHORTCODE );
	}

	public function get_detected_plugin(): ?string {
		$file = $this->get_detected_plugin_file();
		if ( null === $file ) {
			return null;
		}
		// get_plugin_data() lives in wp-admin and is not autoloaded in REST context.
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( trailingslashit( WP_PLUGIN_DIR ) . $file, false, false );
		return ! empty( $data['Name'] ) ? $data['Name'] : $file;
	}

	private function get_detected_plugin_file(): ?string {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $plugin_file ) {
			$lower = strtolower( (string) $plugin_file );
			foreach ( self::PLUGIN_SLUGS as $slug ) {
				if ( str_contains( $lower, $slug ) ) {
					return $plugin_file;
				}
			}
		}
		return null;
	}

	public function find_usages( array $page_ids = array() ): array {
		$args = array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		if ( ! empty( $page_ids ) ) {
			$args['post__in'] = array_map( 'intval', $page_ids );
		}

		$ids    = get_posts( $args );
		$result = array();

		foreach ( $ids as $id ) {
			$page_usages = $this->find_usages_on_page( (int) $id );
			if ( ! empty( $page_usages ) ) {
				array_push( $result, ...$page_usages );
			}
		}

		return $result;
	}

	public function find_usages_on_page( int $page_id ): array {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return array();
		}

		$pattern = '/\[' . preg_quote( self::SHORTCODE, '/' ) . '([^\]]*)\]/';
		preg_match_all( $pattern, $post->post_content, $matches );

		if ( empty( $matches[0] ) ) {
			return array();
		}

		$usages = array();
		foreach ( $matches[0] as $i => $raw ) {
			$atts    = shortcode_parse_atts( trim( $matches[1][ $i ] ) );
			$atts    = is_array( $atts ) ? $atts : array();
			$usages[] = array(
				'page_id'       => $page_id,
				'page_title'    => get_the_title( $post ),
				'page_status'   => $post->post_status,
				'shortcode_raw' => $raw,
				'owner'         => $atts['owner'] ?? null,
				'repo'          => $atts['repo'] ?? null,
				'path'          => $atts['path'] ?? null,
				'url'           => $atts['url'] ?? null,
				'format'        => $atts['format'] ?? null,
				'ttl'           => $atts['ttl'] ?? null,
			);
		}

		return $usages;
	}

	// ── Page-level GitPress workflow ──────────────────────────────────────────

	/**
	 * Create a new draft page and populate its Divi GitHub Sync metabox fields.
	 * Never publishes. Never edits an existing page.
	 */
	public function create_page( array $params ): array|\WP_Error {
		if ( ! $this->is_shortcode_registered() ) {
			return new \WP_Error(
				'broseph_gitpress_inactive',
				'GitPress is not active or the divi_github_content shortcode is not registered.',
				array( 'status' => 422 )
			);
		}

		$title    = sanitize_text_field( $params['title'] ?? '' );
		$shortcode = trim( (string) ( $params['shortcode'] ?? '' ) );

		if ( '' === $title ) {
			return new \WP_Error( 'broseph_bad_request', 'title is required.', array( 'status' => 400 ) );
		}
		if ( '' === $shortcode ) {
			return new \WP_Error( 'broseph_bad_request', 'shortcode is required.', array( 'status' => 400 ) );
		}

		$validation = $this->validate_shortcode( $shortcode );
		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'broseph_invalid_shortcode',
				'Shortcode validation failed: ' . implode( ' ', $validation['errors'] ),
				array( 'status' => 422 )
			);
		}

		$render_mode = isset( $params['render_mode'] )
			? sanitize_key( (string) $params['render_mode'] )
			: ( (bool) ( $params['full_page_canvas'] ?? false ) ? 'full_canvas' : 'theme_wrapped' );

		if ( ! in_array( $render_mode, self::VALID_RENDER_MODES, true ) ) {
			return new \WP_Error(
				'broseph_bad_request',
				'render_mode must be one of: ' . implode( ', ', self::VALID_RENDER_MODES ) . '.',
				array( 'status' => 400 )
			);
		}

		$render_settings = $this->resolve_render_settings( $render_mode, $params, 'after' );

		$slug      = sanitize_title( $params['slug'] ?? $title );
		$excerpt   = wp_kses_post( $params['excerpt'] ?? '' );
		$placement = $render_settings['placement'];
		$full_page = $render_settings['full_page_canvas'];
		$meta      = is_array( $params['meta'] ?? null ) ? $params['meta'] : array();

		$page_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_excerpt' => $excerpt,
				'post_content' => '',
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		update_post_meta( $page_id, self::DGS_META_SHORTCODE, $shortcode );
		update_post_meta( $page_id, self::DGS_META_PLACEMENT, $placement );
		update_post_meta( $page_id, self::DGS_META_RENDER_MODE, $render_mode );

		if ( $full_page ) {
			update_post_meta( $page_id, self::DGS_META_FULL_PAGE, '1' );
		} else {
			delete_post_meta( $page_id, self::DGS_META_FULL_PAGE );
		}

		$this->set_seo_meta( $page_id, $meta );

		return array(
			'status'           => 'draft_created',
			'page_id'          => $page_id,
			'preview_url'      => get_preview_post_link( $page_id ),
			'edit_url'         => admin_url( 'post.php?post=' . $page_id . '&action=edit' ),
			'shortcode'        => $shortcode,
			'render_position'  => $placement,
			'render_mode'      => $render_mode,
			'full_page_canvas' => $full_page,
			'warnings'         => $render_settings['warnings'],
		);
	}

	/**
	 * Convert one existing page in place from Divi/native content to page-level GitPress rendering.
	 * Preserves the original page ID, slug, status, title, parent, featured image, and SEO meta.
	 */
	public function convert_page_to_gitpress( int $page_id, array $params ): array|\WP_Error {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}
		if ( 'page' !== $post->post_type ) {
			return new \WP_Error( 'broseph_unsupported_post_type', 'Only post_type=page is supported in v1.', array( 'status' => 400 ) );
		}

		if ( isset( $params['expected_slug'] ) ) {
			$expected_slug = sanitize_title( (string) $params['expected_slug'] );
			if ( $expected_slug !== $post->post_name ) {
				return new \WP_Error(
					'broseph_slug_conflict',
					'The page slug did not match expected_slug.',
					array( 'status' => 409, 'expected_slug' => $expected_slug, 'current_slug' => $post->post_name )
				);
			}
		}

		if ( isset( $params['expected_status'] ) ) {
			$expected_status = sanitize_key( (string) $params['expected_status'] );
			if ( $expected_status !== $post->post_status ) {
				return new \WP_Error(
					'broseph_status_conflict',
					'The page status did not match expected_status.',
					array( 'status' => 409, 'expected_status' => $expected_status, 'current_status' => $post->post_status )
				);
			}
		}

		if ( ! $this->is_active() && ! $this->is_shortcode_registered() ) {
			return new \WP_Error(
				'broseph_gitpress_inactive',
				'GitPress is not active or the divi_github_content shortcode is not registered.',
				array( 'status' => 422 )
			);
		}

		$shortcode = trim( (string) ( $params['shortcode'] ?? '' ) );
		if ( '' === $shortcode ) {
			return new \WP_Error( 'broseph_bad_request', 'shortcode is required.', array( 'status' => 400 ) );
		}

		$validation = $this->validate_shortcode( $shortcode );
		if ( ! $validation['valid'] ) {
			return new \WP_Error(
				'broseph_invalid_shortcode',
				'Shortcode validation failed: ' . implode( ' ', $validation['errors'] ),
				array( 'status' => 422 )
			);
		}

		$render_mode = sanitize_key( (string) ( $params['render_mode'] ?? 'theme_wrapped' ) );
		if ( ! in_array( $render_mode, self::VALID_RENDER_MODES, true ) ) {
			return new \WP_Error(
				'broseph_bad_request',
				'render_mode must be one of: ' . implode( ', ', self::VALID_RENDER_MODES ) . '.',
				array( 'status' => 400 )
			);
		}

		$placement_requested = sanitize_key( (string) ( $params['render_position'] ?? 'replace' ) );
		if ( ! in_array( $placement_requested, self::VALID_PLACEMENTS, true ) ) {
			return new \WP_Error( 'broseph_bad_request', 'render_position must be before, after, or replace.', array( 'status' => 400 ) );
		}

		$render_settings    = $this->resolve_render_settings(
			$render_mode,
			array_merge( $params, array( 'render_position' => $placement_requested ) ),
			'replace'
		);
		$warnings           = $render_settings['warnings'];
		$placement          = $render_settings['placement'];
		$full_page_canvas   = $render_settings['full_page_canvas'];
		$full_width_content = $render_settings['full_width_content'];
		$backup_first = (bool) ( $params['backup_first'] ?? true );
		$clear_existing_content = (bool) ( $params['clear_existing_content'] ?? true );
		$disable_divi_builder = (bool) ( $params['disable_divi_builder'] ?? true );

		$before = $this->build_conversion_snapshot( $post );
		$backup = array(
			'created' => false,
			'backup_page_id' => null,
			'backup_edit_url' => null,
		);

		if ( $backup_first ) {
			$backup_id = $this->create_conversion_backup( $post );
			if ( is_wp_error( $backup_id ) ) {
				return $backup_id;
			}
			$backup = array(
				'created' => true,
				'backup_page_id' => $backup_id,
				'backup_edit_url' => admin_url( 'post.php?post=' . $backup_id . '&action=edit' ),
			);
		}

		$changed = array();
		$failures = array();

		if ( $clear_existing_content ) {
			$updated = wp_update_post(
				array(
					'ID' => $page_id,
					'post_content' => '',
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				$failures[] = 'Could not clear existing page content: ' . $updated->get_error_message();
			} else {
				$changed[] = 'post_content';
			}
		}

		update_post_meta( $page_id, self::DGS_META_SHORTCODE, $shortcode );
		update_post_meta( $page_id, self::DGS_META_PLACEMENT, $placement );
		update_post_meta( $page_id, self::DGS_META_RENDER_MODE, $render_mode );
		$changed[] = 'gitpress_meta';

		if ( $full_page_canvas ) {
			update_post_meta( $page_id, self::DGS_META_FULL_PAGE, '1' );
		} else {
			delete_post_meta( $page_id, self::DGS_META_FULL_PAGE );
		}

		if ( $full_width_content ) {
			update_post_meta( $page_id, self::DGS_META_FULL_WIDTH, '1' );
		} else {
			delete_post_meta( $page_id, self::DGS_META_FULL_WIDTH );
		}

		if ( $disable_divi_builder ) {
			update_post_meta( $page_id, '_et_pb_use_builder', 'off' );
			delete_post_meta( $page_id, '_et_pb_old_content' );
			$changed[] = 'divi_builder_disabled';
		}

		if ( 'theme_wrapped' === $render_mode && $full_width_content ) {
			update_post_meta( $page_id, '_et_pb_page_layout', 'et_full_width_page' );
			update_post_meta( $page_id, '_et_pb_show_title', 'off' );
			if ( $this->is_blank_or_canvas_template( (string) get_post_meta( $page_id, '_wp_page_template', true ) ) ) {
				update_post_meta( $page_id, '_wp_page_template', 'default' );
				$warnings[] = 'Blank/no-header page template was reset to default for theme_wrapped rendering.';
			}
			$changed[] = 'theme_wrapped_full_width_layout';
		}

		clean_post_cache( $page_id );
		$updated_post = get_post( $page_id );
		if ( ! $updated_post ) {
			$failures[] = 'Could not reload the converted page after mutation.';
		}

		if ( $shortcode !== (string) get_post_meta( $page_id, self::DGS_META_SHORTCODE, true ) ) {
			$failures[] = 'GitPress shortcode meta was not saved correctly.';
		}
		if ( $placement !== (string) get_post_meta( $page_id, self::DGS_META_PLACEMENT, true ) ) {
			$failures[] = 'GitPress render_position meta was not saved correctly.';
		}
		if ( $render_mode !== (string) get_post_meta( $page_id, self::DGS_META_RENDER_MODE, true ) ) {
			$failures[] = 'GitPress render_mode meta was not saved correctly.';
		}
		if ( $full_page_canvas !== ( '1' === (string) get_post_meta( $page_id, self::DGS_META_FULL_PAGE, true ) ) ) {
			$failures[] = 'GitPress full_page_canvas meta was not saved correctly.';
		}
		if ( $full_width_content !== ( '1' === (string) get_post_meta( $page_id, self::DGS_META_FULL_WIDTH, true ) ) ) {
			$failures[] = 'GitPress full_width_content meta was not saved correctly.';
		}

		$after = $updated_post ? array(
			'status' => $updated_post->post_status,
			'slug' => $updated_post->post_name,
			'permalink' => get_permalink( $updated_post ),
			'gitpress_shortcode_set' => '' !== (string) get_post_meta( $page_id, self::DGS_META_SHORTCODE, true ),
			'render_mode' => (string) get_post_meta( $page_id, self::DGS_META_RENDER_MODE, true ),
			'render_position' => (string) get_post_meta( $page_id, self::DGS_META_PLACEMENT, true ),
			'full_width_content' => '1' === (string) get_post_meta( $page_id, self::DGS_META_FULL_WIDTH, true ),
			'full_page_canvas' => '1' === (string) get_post_meta( $page_id, self::DGS_META_FULL_PAGE, true ),
			'divi_builder_disabled' => 'on' !== (string) get_post_meta( $page_id, '_et_pb_use_builder', true ),
			'content_length' => strlen( $updated_post->post_content ),
		) : array();

		foreach ( $failures as $failure ) {
			$warnings[] = $failure;
		}

		return array(
			'status' => empty( $failures ) ? 'converted' : 'partial',
			'page_id' => $page_id,
			'backup' => $backup,
			'before' => $before,
			'after' => $after,
			'preview_url' => $updated_post && 'draft' === $updated_post->post_status ? get_preview_post_link( $updated_post ) : get_permalink( $page_id ),
			'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=edit' ),
			'warnings' => $warnings,
			'changed' => array_values( array_unique( $changed ) ),
		);
	}

	/**
	 * Read the Divi GitHub Sync metabox settings for an existing page.
	 */
	public function get_page_settings( int $page_id ): array|\WP_Error {
		$post = get_post( $page_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$shortcode       = (string) get_post_meta( $page_id, self::DGS_META_SHORTCODE, true );
		$placement       = (string) get_post_meta( $page_id, self::DGS_META_PLACEMENT, true );
		$full_page       = '1' === (string) get_post_meta( $page_id, self::DGS_META_FULL_PAGE, true );
		$render_mode     = (string) get_post_meta( $page_id, self::DGS_META_RENDER_MODE, true );
		$full_width      = '1' === (string) get_post_meta( $page_id, self::DGS_META_FULL_WIDTH, true );
		$has_inline      = str_contains( $post->post_content, '[' . self::SHORTCODE );

		return array(
			'page_id'                => $page_id,
			'title'                  => $post->post_title,
			'shortcode'              => '' !== $shortcode ? $shortcode : null,
			'render_position'        => '' !== $placement ? $placement : null,
			'render_mode'            => '' !== $render_mode ? $render_mode : null,
			'full_page_canvas'       => $full_page,
			'full_width_content'     => $full_width,
			'has_gitpress_shortcode' => '' !== $shortcode || $has_inline,
			'has_inline_shortcode'   => $has_inline,
			'preview_url'            => 'draft' === $post->post_status ? get_preview_post_link( $post ) : null,
			'edit_url'               => admin_url( 'post.php?post=' . $page_id . '&action=edit' ),
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Accepts both long-form API values (e.g. "after_content") and native
	 * DGS plugin values ("before", "after", "replace"). Defaults to "after".
	 */
	private function normalize_placement( string $input ): string {
		$map = array(
			'before_content'  => 'before',
			'after_content'   => 'after',
			'replace_content' => 'replace',
			'before'          => 'before',
			'after'           => 'after',
			'replace'         => 'replace',
		);
		return $map[ $input ] ?? 'after';
	}

	/**
	 * Normalizes full_page_canvas / render_position / full_width_content against
	 * render_mode per the GitPress render-mode contract. Conflicting values are
	 * normalized safely rather than rejected, and surfaced as warnings.
	 */
	private function resolve_render_settings( string $render_mode, array $params, string $default_placement ): array {
		$warnings = array();

		$requested_full_page  = array_key_exists( 'full_page_canvas', $params ) ? (bool) $params['full_page_canvas'] : null;
		$requested_full_width = (bool) ( $params['full_width_content'] ?? false );
		$requested_placement  = $this->normalize_placement( (string) ( $params['render_position'] ?? $default_placement ) );

		switch ( $render_mode ) {
			case 'theme_wrapped':
				$full_page_canvas = false;
				if ( true === $requested_full_page ) {
					$warnings[] = 'full_page_canvas was normalized to false because render_mode is theme_wrapped.';
				}
				$full_width_content = $requested_full_width;
				$placement          = $requested_placement;
				break;

			case 'full_canvas':
				$full_page_canvas = true;
				if ( false === $requested_full_page ) {
					$warnings[] = 'full_page_canvas was normalized to true because render_mode is full_canvas.';
				}
				$full_width_content = $requested_full_width;
				$placement          = $requested_placement;
				break;

			case 'gitpress_managed':
				$full_page_canvas = true;
				if ( false === $requested_full_page ) {
					$warnings[] = 'full_page_canvas was normalized to true because render_mode is gitpress_managed.';
				}
				if ( 'replace' !== $requested_placement ) {
					$warnings[] = 'render_position was normalized to replace because render_mode is gitpress_managed.';
				}
				$placement = 'replace';
				if ( $requested_full_width ) {
					$warnings[] = 'full_width_content was ignored because render_mode is gitpress_managed.';
				}
				$full_width_content = false;
				break;

			default:
				$full_page_canvas   = $requested_full_page ?? false;
				$full_width_content = $requested_full_width;
				$placement          = $requested_placement;
		}

		return array(
			'full_page_canvas'   => $full_page_canvas,
			'placement'          => $placement,
			'full_width_content' => $full_width_content,
			'warnings'           => $warnings,
		);
	}

	private function set_seo_meta( int $page_id, array $meta ): void {
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

	private function build_conversion_snapshot( \WP_Post $post ): array {
		$shortcode = (string) get_post_meta( $post->ID, self::DGS_META_SHORTCODE, true );
		$render_mode = (string) get_post_meta( $post->ID, self::DGS_META_RENDER_MODE, true );

		return array(
			'page_id' => $post->ID,
			'title' => $post->post_title,
			'slug' => $post->post_name,
			'status' => $post->post_status,
			'permalink' => get_permalink( $post ),
			'had_divi_builder' => 'on' === (string) get_post_meta( $post->ID, '_et_pb_use_builder', true ) || str_contains( $post->post_content, '[et_pb_section' ),
			'content_length' => strlen( $post->post_content ),
			'had_gitpress_meta' => '' !== $shortcode,
			'current_render_mode' => '' !== $render_mode ? $render_mode : null,
			'current_shortcode_present' => '' !== $shortcode || str_contains( $post->post_content, '[' . self::SHORTCODE ),
		);
	}

	private function create_conversion_backup( \WP_Post $source ): int|\WP_Error {
		$title = 'Backup: ' . $source->post_title . ' - Pre GitPress Conversion';
		$slug = wp_unique_post_slug( sanitize_title( 'backup-' . $source->post_name . '-pre-gitpress-conversion' ), 0, 'draft', 'page', (int) $source->post_parent );

		$backup_id = wp_insert_post(
			array(
				'post_author' => $source->post_author,
				'post_title' => $title,
				'post_name' => $slug,
				'post_excerpt' => $source->post_excerpt,
				'post_content' => $source->post_content,
				'post_status' => 'draft',
				'post_type' => 'page',
				'post_parent' => $source->post_parent,
				'menu_order' => $source->menu_order,
				'comment_status' => $source->comment_status,
				'ping_status' => $source->ping_status,
			),
			true
		);

		if ( is_wp_error( $backup_id ) ) {
			return $backup_id;
		}

		$all_meta = get_post_meta( $source->ID );
		foreach ( $all_meta as $key => $values ) {
			if ( in_array( (string) $key, self::BACKUP_BLOCKED_META, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $backup_id, (string) $key, maybe_unserialize( $value ) );
			}
		}

		return $backup_id;
	}

	private function is_blank_or_canvas_template( string $template ): bool {
		if ( '' === $template || 'default' === $template ) {
			return false;
		}

		$template = strtolower( $template );
		return str_contains( $template, 'blank' )
			|| str_contains( $template, 'canvas' )
			|| str_contains( $template, 'no-header' )
			|| str_contains( $template, 'no_header' );
	}

	// ── GitPress Managed global layout ────────────────────────────────────────

	/**
	 * Reads the global GitPress Managed header/footer shortcodes.
	 * Read-only; never mutates options.
	 */
	public function get_managed_layout(): array {
		$active = $this->is_active();
		$header = (string) get_option( self::OPT_MANAGED_HEADER, '' );
		$footer = (string) get_option( self::OPT_MANAGED_FOOTER, '' );

		$warnings = array();
		if ( ! $active ) {
			$warnings[] = 'GitPress is not active.';
		}

		return array(
			'gitpress_active'        => $active,
			'render_mode_supported'  => in_array( 'gitpress_managed', self::VALID_RENDER_MODES, true ),
			'header'                 => array(
				'shortcode' => '' !== $header ? $this->redact_layout_shortcode( $header ) : null,
				'is_set'    => '' !== $header,
			),
			'footer'                 => array(
				'shortcode' => '' !== $footer ? $this->redact_layout_shortcode( $footer ) : null,
				'is_set'    => '' !== $footer,
			),
			'warnings'               => $warnings,
		);
	}

	/**
	 * Updates the global GitPress Managed header/footer shortcodes.
	 * Only fields explicitly present in $params are touched. Invalid shortcodes
	 * are rejected per-field without saving; valid fields are saved independently.
	 */
	public function update_managed_layout( array $params ): array|\WP_Error {
		if ( ! $this->is_active() ) {
			return new \WP_Error(
				'broseph_gitpress_inactive',
				'GitPress is not active.',
				array( 'status' => 422 )
			);
		}

		$has_header = array_key_exists( 'header_shortcode', $params );
		$has_footer = array_key_exists( 'footer_shortcode', $params );

		if ( ! $has_header && ! $has_footer ) {
			return new \WP_Error(
				'broseph_bad_request',
				'At least one of header_shortcode or footer_shortcode is required.',
				array( 'status' => 400 )
			);
		}

		$before = array(
			'header_shortcode_set' => '' !== (string) get_option( self::OPT_MANAGED_HEADER, '' ),
			'footer_shortcode_set' => '' !== (string) get_option( self::OPT_MANAGED_FOOTER, '' ),
		);

		$updated  = array( 'header_shortcode' => false, 'footer_shortcode' => false );
		$warnings = array();

		if ( $has_header ) {
			$header_shortcode = trim( (string) $params['header_shortcode'] );
			$validation        = $this->validate_layout_shortcode( $header_shortcode );
			if ( $validation['valid'] ) {
				update_option( self::OPT_MANAGED_HEADER, $header_shortcode );
				$updated['header_shortcode'] = true;
			} else {
				$warnings[] = 'header_shortcode was not saved: ' . implode( ' ', $validation['errors'] );
			}
		}

		if ( $has_footer ) {
			$footer_shortcode = trim( (string) $params['footer_shortcode'] );
			$validation        = $this->validate_layout_shortcode( $footer_shortcode );
			if ( $validation['valid'] ) {
				update_option( self::OPT_MANAGED_FOOTER, $footer_shortcode );
				$updated['footer_shortcode'] = true;
			} else {
				$warnings[] = 'footer_shortcode was not saved: ' . implode( ' ', $validation['errors'] );
			}
		}

		$this->clear_gitpress_cache();

		$after = array(
			'header_shortcode_set' => '' !== (string) get_option( self::OPT_MANAGED_HEADER, '' ),
			'footer_shortcode_set' => '' !== (string) get_option( self::OPT_MANAGED_FOOTER, '' ),
		);

		return array(
			'status'   => 'updated',
			'updated'  => $updated,
			'before'   => $before,
			'after'    => $after,
			'warnings' => $warnings,
		);
	}

	/**
	 * Validates a GitPress Managed layout shortcode. Restricted to [divi_github]
	 * and [divi_github_content] and rejects script/PHP tags, javascript: URLs,
	 * credential URLs, and token/secret/key-style query params.
	 */
	private function validate_layout_shortcode( string $shortcode ): array {
		$shortcode = trim( $shortcode );
		if ( '' === $shortcode ) {
			return array( 'valid' => false, 'errors' => array( 'Shortcode is required.' ) );
		}

		$lower = strtolower( $shortcode );
		$errors = array();

		if ( str_contains( $lower, '<script' ) || str_contains( $lower, '<?php' ) || str_contains( $lower, 'javascript:' ) ) {
			$errors[] = 'Shortcode contains disallowed content.';
		}
		if ( preg_match( '/(token|secret|key|password|apikey|api_key)\s*=/i', $shortcode ) ) {
			$errors[] = 'Shortcode must not contain credential-like query parameters.';
		}
		if ( preg_match( '#https?://[^\s\]"\']*[:@][^\s\]"\']*#i', $shortcode ) ) {
			$errors[] = 'Shortcode must not contain credential URLs.';
		}
		if ( ! empty( $errors ) ) {
			return array( 'valid' => false, 'errors' => $errors );
		}

		if ( class_exists( 'DGS_Shortcode_Handler' ) && method_exists( 'DGS_Shortcode_Handler', 'validate_shortcode_string' ) ) {
			$result = \DGS_Shortcode_Handler::validate_shortcode_string( $shortcode );
			if ( is_array( $result ) ) {
				return array( 'valid' => ! empty( $result['valid'] ), 'errors' => $result['errors'] ?? array() );
			}
			return true === $result
				? array( 'valid' => true, 'errors' => array() )
				: array( 'valid' => false, 'errors' => array( 'GitPress shortcode validation failed.' ) );
		}

		// Fallback validator restricted to the allowed layout shortcode tags.
		$pattern = '/^\[(\w+)(.*)\]$/s';
		if ( ! preg_match( $pattern, $shortcode, $m ) ) {
			return array( 'valid' => false, 'errors' => array( 'Not a valid shortcode syntax.' ) );
		}
		if ( ! in_array( $m[1], self::LAYOUT_VALID_SHORTCODES, true ) ) {
			return array(
				'valid'  => false,
				'errors' => array( 'Shortcode must be one of: ' . implode( ', ', self::LAYOUT_VALID_SHORTCODES ) . '.' ),
			);
		}

		$atts = shortcode_parse_atts( trim( $m[2] ) );
		$atts = is_array( $atts ) ? $atts : array();

		$has_url   = ! empty( $atts['url'] );
		$has_parts = ! empty( $atts['owner'] ) && ! empty( $atts['repo'] ) && ! empty( $atts['path'] );
		if ( ! $has_url && ! $has_parts ) {
			$errors[] = 'Must have either "url" or all of "owner", "repo", "path".';
		}
		if ( isset( $atts['format'] ) && ! in_array( $atts['format'], self::VALID_FORMATS, true ) ) {
			$errors[] = 'format must be one of: ' . implode( ', ', self::VALID_FORMATS ) . '.';
		}

		return array( 'valid' => empty( $errors ), 'errors' => $errors );
	}

	/**
	 * Defensive output mask in case a shortcode containing credential-like
	 * attributes was stored outside Broseph (e.g. directly in GitPress admin).
	 */
	private function redact_layout_shortcode( string $shortcode ): string {
		return (string) preg_replace(
			'/((?:token|secret|key|password|apikey|api_key)\s*=\s*")([^"]*)(")/i',
			'$1***$3',
			$shortcode
		);
	}

	private function clear_gitpress_cache(): void {
		if ( function_exists( 'dgs_clear_cache' ) ) {
			dgs_clear_cache();
			return;
		}
		if ( class_exists( 'DGS_Cache' ) && method_exists( 'DGS_Cache', 'clear_all' ) ) {
			\DGS_Cache::clear_all();
		}
	}

	public function validate_shortcode( string $shortcode ): array {
		$errors = array();

		$pattern = '/^\[(\w+)(.*)\]$/s';
		if ( ! preg_match( $pattern, trim( $shortcode ), $m ) ) {
			return array( 'valid' => false, 'errors' => array( 'Not a valid shortcode syntax.' ) );
		}

		if ( $m[1] !== self::SHORTCODE ) {
			return array( 'valid' => false, 'errors' => array( 'Shortcode must be ' . self::SHORTCODE . '.' ) );
		}

		$atts = shortcode_parse_atts( trim( $m[2] ) );
		$atts = is_array( $atts ) ? $atts : array();

		$has_url   = ! empty( $atts['url'] );
		$has_parts = ! empty( $atts['owner'] ) && ! empty( $atts['repo'] ) && ! empty( $atts['path'] );

		if ( ! $has_url && ! $has_parts ) {
			$errors[] = 'Must have either "url" or all of "owner", "repo", "path".';
		}

		if ( isset( $atts['format'] ) && ! in_array( $atts['format'], self::VALID_FORMATS, true ) ) {
			$errors[] = 'format must be one of: ' . implode( ', ', self::VALID_FORMATS ) . '.';
		}

		return array( 'valid' => empty( $errors ), 'errors' => $errors );
	}
}
