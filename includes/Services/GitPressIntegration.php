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

	// Valid placement values accepted by the DGS plugin.
	private const VALID_PLACEMENTS = array( 'before', 'after', 'replace' );

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

		$slug      = sanitize_title( $params['slug'] ?? $title );
		$excerpt   = wp_kses_post( $params['excerpt'] ?? '' );
		$placement = $this->normalize_placement( (string) ( $params['render_position'] ?? 'after' ) );
		$full_page = (bool) ( $params['full_page_canvas'] ?? false );
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
			'full_page_canvas' => $full_page,
			'warnings'         => array(),
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
		$has_inline      = str_contains( $post->post_content, '[' . self::SHORTCODE );

		return array(
			'page_id'                => $page_id,
			'title'                  => $post->post_title,
			'shortcode'              => '' !== $shortcode ? $shortcode : null,
			'render_position'        => '' !== $placement ? $placement : null,
			'full_page_canvas'       => $full_page,
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
