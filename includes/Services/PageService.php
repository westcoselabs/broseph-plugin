<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageService {

	private const BULK_LIMIT = 25;
	private const PUBLISHABLE_STATUSES = array( 'draft', 'pending' );
	private const TRASHABLE_STATUSES = array( 'draft', 'pending' );

	// Meta keys that are safe to copy when duplicating a page.
	private const SAFE_COPY_META = array( '_wp_page_template' );

	// SEO meta keys copied when copy_meta is true.
	private const SEO_META = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'rank_math_title',
		'rank_math_description',
	);

	// Explicit allowlist for meta accepted via the create_draft endpoint (v1).
	private const ALLOWED_CREATE_META = array(
		'_wp_page_template',
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'rank_math_title',
		'rank_math_description',
	);

	// Meta keys never copied on duplicate.
	private const BLOCKED_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_pingme', '_encloseme' );

	// Divi builder meta keys to copy when duplicating.
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

	public function get_pages( array $params = array() ): array {
		$status_raw  = $params['status'] ?? 'publish,draft,pending,private';
		$statuses    = array_filter( array_map( 'trim', explode( ',', $status_raw ) ) );

		// Restrict to safe WP statuses to prevent arbitrary query injection.
		$safe_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' );
		$statuses      = array_values( array_filter( $statuses, static fn( $s ) => in_array( $s, $safe_statuses, true ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'publish', 'draft', 'pending', 'private' );
		}

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
			if ( in_array( $key, self::ALLOWED_CREATE_META, true ) ) {
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

		$this->copy_builder_meta( $source_id, $new_id, $source->post_content );

		return $new_id;
	}

	public function publish_page( int $page_id, ?string $expected_current_status = null ): array|\WP_Error {
		$post = $this->get_mutable_page( $page_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$previous_status = $post->post_status;

		if ( null !== $expected_current_status ) {
			$expected = sanitize_key( $expected_current_status );
			if ( $expected !== $previous_status ) {
				return new \WP_Error(
					'broseph_status_conflict',
					'The page status did not match expected_current_status.',
					array(
						'status'           => 409,
						'page_id'          => $page_id,
						'expected_status'  => $expected,
						'current_status'   => $previous_status,
					)
				);
			}
		}

		if ( 'publish' === $previous_status ) {
			return array(
				'status'          => 'already_published',
				'page_id'         => $page_id,
				'previous_status' => $previous_status,
				'new_status'      => $previous_status,
				'permalink'       => get_permalink( $post ),
				'warnings'        => array(),
			);
		}

		if ( ! in_array( $previous_status, self::PUBLISHABLE_STATUSES, true ) ) {
			return new \WP_Error(
				'broseph_invalid_status',
				'Only draft and pending pages can be published.',
				array(
					'status'          => 400,
					'page_id'         => $page_id,
					'current_status'  => $previous_status,
				)
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $page_id,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated_post = get_post( $page_id );
		$new_status   = $updated_post ? $updated_post->post_status : 'publish';

		return array(
			'status'          => 'published',
			'page_id'         => $page_id,
			'previous_status' => $previous_status,
			'new_status'      => $new_status,
			'permalink'       => $updated_post ? get_permalink( $updated_post ) : get_permalink( $page_id ),
			'warnings'        => array(),
		);
	}

	public function bulk_publish_pages( array $page_ids ): array|\WP_Error {
		$normalized = $this->normalize_bulk_page_ids( $page_ids );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$results = array();
		$summary = array(
			'requested' => count( $normalized ),
			'published' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		foreach ( $normalized as $page_id ) {
			$result = $this->publish_page( $page_id );
			if ( is_wp_error( $result ) ) {
				$summary['failed']++;
				$results[] = array(
					'page_id' => $page_id,
					'status'  => 'failed',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				);
				continue;
			}

			if ( 'published' === $result['status'] ) {
				$summary['published']++;
			} else {
				$summary['skipped']++;
			}

			$results[] = $result;
		}

		return array(
			'summary'  => $summary,
			'results'  => $results,
			'warnings' => array(),
		);
	}

	public function trash_draft_page( int $page_id ): array|\WP_Error {
		$post = $this->get_mutable_page( $page_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$previous_status = $post->post_status;

		if ( 'trash' === $previous_status ) {
			return array(
				'status'          => 'already_trashed',
				'page_id'         => $page_id,
				'previous_status' => $previous_status,
				'new_status'      => $previous_status,
			);
		}

		if ( ! in_array( $previous_status, self::TRASHABLE_STATUSES, true ) ) {
			return new \WP_Error(
				'broseph_invalid_status',
				'Only draft and pending pages can be trashed.',
				array(
					'status'         => 400,
					'page_id'        => $page_id,
					'current_status' => $previous_status,
				)
			);
		}

		$result = wp_trash_post( $page_id );
		if ( false === $result ) {
			return new \WP_Error(
				'broseph_trash_failed',
				'WordPress could not trash the page.',
				array( 'status' => 500, 'page_id' => $page_id )
			);
		}

		$updated_post = get_post( $page_id );

		return array(
			'status'          => 'trashed',
			'page_id'         => $page_id,
			'previous_status' => $previous_status,
			'new_status'      => $updated_post ? $updated_post->post_status : 'trash',
		);
	}

	public function bulk_trash_pages( array $page_ids ): array|\WP_Error {
		$normalized = $this->normalize_bulk_page_ids( $page_ids );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$results = array();
		$summary = array(
			'requested' => count( $normalized ),
			'trashed'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		foreach ( $normalized as $page_id ) {
			$result = $this->trash_draft_page( $page_id );
			if ( is_wp_error( $result ) ) {
				$summary['failed']++;
				$results[] = array(
					'page_id' => $page_id,
					'status'  => 'failed',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				);
				continue;
			}

			if ( 'trashed' === $result['status'] ) {
				$summary['trashed']++;
			} else {
				$summary['skipped']++;
			}

			$results[] = $result;
		}

		return array(
			'summary'  => $summary,
			'results'  => $results,
			'warnings' => array(),
		);
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

	private function get_mutable_page( int $page_id ): \WP_Post|\WP_Error {
		$post = get_post( $page_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		return $post;
	}

	private function normalize_bulk_page_ids( array $page_ids ): array|\WP_Error {
		if ( empty( $page_ids ) ) {
			return new \WP_Error(
				'broseph_bad_request',
				'page_ids must be a non-empty array.',
				array( 'status' => 400 )
			);
		}

		if ( count( $page_ids ) > self::BULK_LIMIT ) {
			return new \WP_Error(
				'broseph_bad_request',
				'A maximum of 25 page IDs is allowed per request.',
				array( 'status' => 400 )
			);
		}

		$normalized = array();
		foreach ( $page_ids as $page_id ) {
			$page_id = (int) $page_id;
			if ( $page_id <= 0 ) {
				return new \WP_Error(
					'broseph_bad_request',
					'Each page_id must be a positive integer.',
					array( 'status' => 400 )
				);
			}

			$normalized[] = $page_id;
		}

		return array_values( array_unique( $normalized ) );
	}
}
