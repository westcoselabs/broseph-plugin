<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageService {

	// Meta keys that are safe to copy when duplicating a page.
	private const SAFE_COPY_META = array( '_wp_page_template' );

	// SEO meta keys copied when copy_meta is true.
	private const SEO_META = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'rank_math_title',
		'rank_math_description',
	);

	// Meta keys never copied.
	private const BLOCKED_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_pingme', '_encloseme' );

	public function get_pages( array $params = array() ): array {
		$status_raw  = $params['status'] ?? 'publish,draft,pending,private';
		$statuses    = array_filter( array_map( 'trim', explode( ',', $status_raw ) ) );

		$query_args = array(
			'post_type'      => 'page',
			'post_status'    => $statuses,
			'posts_per_page' => min( (int) ( $params['per_page'] ?? 50 ), 100 ),
			'paged'          => max( 1, (int) ( $params['page'] ?? 1 ) ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! empty( $params['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $params['search'] );
		}

		$query = new \WP_Query( $query_args );
		$pages = array();

		foreach ( $query->posts as $post ) {
			$pages[] = $this->format_page_summary( $post );
		}

		return array(
			'pages'       => $pages,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	public function get_page( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return null;
		}
		return $this->format_page_detail( $post );
	}

	public function create_draft( array $data ): int|\WP_Error {
		$title   = sanitize_text_field( $data['title'] ?? '' );
		$slug    = sanitize_title( $data['slug'] ?? $title );
		$excerpt = wp_kses_post( $data['excerpt'] ?? '' );
		$content = $data['content'] ?? '';
		$meta    = is_array( $data['meta'] ?? null ) ? $data['meta'] : array();

		if ( empty( $title ) ) {
			return new \WP_Error( 'broseph_missing_title', 'Title is required.', array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_excerpt' => $excerpt,
				'post_content' => $content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! in_array( $key, self::BLOCKED_META, true ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		return $post_id;
	}

	public function duplicate( int $source_id, string $new_title, string $new_slug, bool $copy_meta = true ): int|\WP_Error {
		$source = get_post( $source_id );
		if ( ! $source || 'page' !== $source->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Source page not found.', array( 'status' => 404 ) );
		}

		$title = sanitize_text_field( $new_title ) ?: $source->post_title . ' (Copy)';
		$slug  = sanitize_title( $new_slug ) ?: sanitize_title( $title );

		$new_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_excerpt' => $source->post_excerpt,
				'post_content' => $source->post_content,
				'post_status'  => 'draft',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Always copy page template.
		foreach ( self::SAFE_COPY_META as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' !== $value ) {
				update_post_meta( $new_id, $key, $value );
			}
		}

		// Copy SEO meta if requested.
		if ( $copy_meta ) {
			foreach ( self::SEO_META as $key ) {
				$value = get_post_meta( $source_id, $key, true );
				if ( '' !== $value ) {
					update_post_meta( $new_id, $key, $value );
				}
			}
		}

		return $new_id;
	}

	private function format_page_summary( \WP_Post $post ): array {
		$content = $post->post_content;
		return array(
			'id'                    => $post->ID,
			'title'                 => $post->post_title,
			'slug'                  => $post->post_name,
			'status'                => $post->post_status,
			'modified'              => $post->post_modified_gmt,
			'link'                  => get_permalink( $post ),
			'edit_link'             => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'is_divi_page'          => str_contains( $content, '[et_pb_section' ),
			'has_gitpress_shortcodes' => str_contains( $content, '[divi_github_content' ),
			'template_hint'         => $this->get_template_hint( $post->ID ),
		);
	}

	private function format_page_detail( \WP_Post $post ): array {
		$content          = $post->post_content;
		$shortcode_count  = substr_count( $content, '[divi_github_content' );
		$preview_link     = 'draft' === $post->post_status ? get_preview_post_link( $post ) : null;

		return array(
			'id'                        => $post->ID,
			'title'                     => $post->post_title,
			'slug'                      => $post->post_name,
			'status'                    => $post->post_status,
			'excerpt'                   => $post->post_excerpt,
			'content_raw'               => $content,
			'modified'                  => $post->post_modified_gmt,
			'link'                      => get_permalink( $post ),
			'preview_link'              => $preview_link,
			'edit_link'                 => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'is_divi_page'              => str_contains( $content, '[et_pb_section' ),
			'has_gitpress_shortcodes'   => str_contains( $content, '[divi_github_content' ),
			'gitpress_shortcodes_found' => $shortcode_count,
			'seo_meta'                  => array(
				'yoast_title'          => get_post_meta( $post->ID, '_yoast_wpseo_title', true ) ?: null,
				'yoast_description'    => get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ) ?: null,
				'rank_math_title'      => get_post_meta( $post->ID, 'rank_math_title', true ) ?: null,
				'rank_math_description' => get_post_meta( $post->ID, 'rank_math_description', true ) ?: null,
			),
		);
	}

	private function get_template_hint( int $post_id ): ?string {
		$template = get_post_meta( $post_id, '_wp_page_template', true );
		if ( ! $template || 'default' === $template ) {
			return null;
		}
		return basename( $template, '.php' );
	}

	public function page_to_snapshot( \WP_Post $post ): array {
		return array(
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'content_length' => strlen( $post->post_content ),
			'modified'       => $post->post_modified_gmt,
		);
	}
}
