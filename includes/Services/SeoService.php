<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoService {

	private const SUPPORTED_POST_TYPES = array( 'page', 'post' );

	private const YOAST_FIELDS = array(
		'title'            => '_yoast_wpseo_title',
		'description'      => '_yoast_wpseo_metadesc',
		'focus_keyphrase'  => '_yoast_wpseo_focuskw',
	);

	private const RANK_MATH_FIELDS = array(
		'title'            => 'rank_math_title',
		'description'      => 'rank_math_description',
		'focus_keyphrase'  => 'rank_math_focus_keyword',
	);

	public function get_page_seo( int $post_id ): array|\WP_Error {
		$post = $this->get_supported_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$seo_plugin = $this->detect_seo_plugin( $post_id );
		$warnings   = array();

		if ( 'none' === $seo_plugin ) {
			$warnings[] = 'No supported SEO plugin was detected; returning stored metadata only.';
		}

		return array(
			'page_id'     => $post->ID,
			'post_type'   => $post->post_type,
			'title'       => $post->post_title,
			'slug'        => $post->post_name,
			'seo_plugin'  => $seo_plugin,
			'yoast'       => $this->read_plugin_meta( $post_id, self::YOAST_FIELDS ),
			'rank_math'   => array(
				'title'         => $this->get_meta_string( $post_id, self::RANK_MATH_FIELDS['title'] ),
				'description'   => $this->get_meta_string( $post_id, self::RANK_MATH_FIELDS['description'] ),
				'focus_keyword' => $this->get_meta_string( $post_id, self::RANK_MATH_FIELDS['focus_keyphrase'] ),
			),
			'warnings'    => $warnings,
		);
	}

	public function update_page_seo( int $post_id, array $data ): array|\WP_Error {
		$post = $this->get_supported_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$plugin = $this->normalize_plugin( $data['seo_plugin'] ?? null, $post_id );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		$provided = $this->extract_explicit_fields( $data );
		if ( empty( $provided ) ) {
			return new \WP_Error(
				'broseph_bad_request',
				'At least one of title, description, focus_keyphrase, or slug must be provided.',
				array( 'status' => 400 )
			);
		}

		$meta_map = 'rank_math' === $plugin ? self::RANK_MATH_FIELDS : self::YOAST_FIELDS;
		$before   = $this->read_update_snapshot( $post_id, $meta_map );
		$after    = $before;
		$updated  = array(
			'title'            => false,
			'description'      => false,
			'focus_keyphrase'  => false,
			'slug'             => false,
		);

		if ( array_key_exists( 'title', $provided ) ) {
			update_post_meta( $post_id, $meta_map['title'], $provided['title'] );
			$after['title']    = $provided['title'];
			$updated['title']  = true;
		}

		if ( array_key_exists( 'description', $provided ) ) {
			update_post_meta( $post_id, $meta_map['description'], $provided['description'] );
			$after['description']   = $provided['description'];
			$updated['description'] = true;
		}

		if ( array_key_exists( 'focus_keyphrase', $provided ) ) {
			update_post_meta( $post_id, $meta_map['focus_keyphrase'], $provided['focus_keyphrase'] );
			$after['focus_keyphrase']   = $provided['focus_keyphrase'];
			$updated['focus_keyphrase'] = true;
		}

		if ( array_key_exists( 'slug', $provided ) ) {
			if ( '' === $provided['slug'] ) {
				return new \WP_Error(
					'broseph_bad_request',
					'slug must contain at least one valid character when provided.',
					array( 'status' => 400 )
				);
			}

			$unique_slug = wp_unique_post_slug(
				$provided['slug'],
				$post->ID,
				$post->post_status,
				$post->post_type,
				(int) $post->post_parent
			);

			$result = wp_update_post(
				array(
					'ID'        => $post->ID,
					'post_name' => $unique_slug,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_post    = get_post( $post->ID );
			$after['slug']   = $updated_post ? $updated_post->post_name : $unique_slug;
			$updated['slug'] = true;
		}

		$warnings = $this->build_validation_warnings( $after );
		if ( array_key_exists( 'slug', $provided ) && $provided['slug'] !== $after['slug'] ) {
			$warnings[] = 'Requested slug was adjusted to keep it unique.';
		}
		if ( 'none' === $this->detect_seo_plugin( $post_id ) ) {
			$warnings[] = 'Updated metadata was saved, but no supported SEO plugin is currently detected.';
		}

		return array(
			'status'      => 'updated',
			'page_id'     => $post->ID,
			'seo_plugin'  => $plugin,
			'updated'     => $updated,
			'before'      => $before,
			'after'       => $after,
			'warnings'    => array_values( array_unique( $warnings ) ),
		);
	}

	public function bulk_update( array $items ): array|\WP_Error {
		if ( empty( $items ) ) {
			return new \WP_Error( 'broseph_bad_request', 'items must be a non-empty array.', array( 'status' => 400 ) );
		}

		if ( count( $items ) > 25 ) {
			return new \WP_Error( 'broseph_bad_request', 'A maximum of 25 items is allowed per bulk request.', array( 'status' => 400 ) );
		}

		$results = array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				$results[] = array(
					'index'   => $index,
					'status'  => 'error',
					'message' => 'Each bulk item must be an object.',
				);
				continue;
			}

			$page_id = isset( $item['page_id'] ) ? (int) $item['page_id'] : 0;
			if ( $page_id <= 0 ) {
				$results[] = array(
					'index'   => $index,
					'status'  => 'error',
					'message' => 'page_id is required for each bulk item.',
				);
				continue;
			}

			$result = $this->update_page_seo( $page_id, $item );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'index'    => $index,
					'page_id'  => $page_id,
					'status'   => 'error',
					'code'     => $result->get_error_code(),
					'message'  => $result->get_error_message(),
					'data'     => $result->get_error_data(),
				);
				continue;
			}

			$results[] = $result;
		}

		return array(
			'status'  => 'completed',
			'results' => $results,
		);
	}

	public function detect_seo_plugin( int $post_id ): string {
		if ( $this->is_yoast_detected( $post_id ) ) {
			return 'yoast';
		}

		if ( $this->is_rank_math_detected( $post_id ) ) {
			return 'rank_math';
		}

		return 'none';
	}

	private function get_supported_post( int $post_id ): \WP_Post|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'broseph_not_found', 'Page or post not found.', array( 'status' => 404 ) );
		}

		if ( ! in_array( $post->post_type, self::SUPPORTED_POST_TYPES, true ) ) {
			return new \WP_Error(
				'broseph_unsupported_post_type',
				'Only page and post types are supported.',
				array( 'status' => 400, 'post_type' => $post->post_type )
			);
		}

		return $post;
	}

	private function normalize_plugin( mixed $requested, int $post_id ): string|\WP_Error {
		if ( null === $requested || '' === $requested ) {
			$detected = $this->detect_seo_plugin( $post_id );
			if ( 'yoast' === $detected ) {
				return 'yoast';
			}
			if ( 'rank_math' === $detected ) {
				return 'rank_math';
			}
			return 'yoast';
		}

		$plugin = sanitize_key( (string) $requested );
		if ( ! in_array( $plugin, array( 'yoast', 'rank_math' ), true ) ) {
			return new \WP_Error(
				'broseph_bad_request',
				'seo_plugin must be yoast or rank_math when provided.',
				array( 'status' => 400 )
			);
		}

		return $plugin;
	}

	private function extract_explicit_fields( array $data ): array {
		$fields = array();

		if ( array_key_exists( 'title', $data ) ) {
			$fields['title'] = sanitize_text_field( (string) $data['title'] );
		}

		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = sanitize_text_field( (string) $data['description'] );
		}

		if ( array_key_exists( 'focus_keyphrase', $data ) ) {
			$fields['focus_keyphrase'] = sanitize_text_field( (string) $data['focus_keyphrase'] );
		}

		if ( array_key_exists( 'slug', $data ) ) {
			$fields['slug'] = sanitize_title( (string) $data['slug'] );
		}

		return $fields;
	}

	private function read_plugin_meta( int $post_id, array $meta_map ): array {
		return array(
			'title'           => $this->get_meta_string( $post_id, $meta_map['title'] ),
			'description'     => $this->get_meta_string( $post_id, $meta_map['description'] ),
			'focus_keyphrase' => $this->get_meta_string( $post_id, $meta_map['focus_keyphrase'] ),
		);
	}

	private function read_update_snapshot( int $post_id, array $meta_map ): array {
		$post = get_post( $post_id );

		return array(
			'title'           => $this->get_meta_string( $post_id, $meta_map['title'] ),
			'description'     => $this->get_meta_string( $post_id, $meta_map['description'] ),
			'focus_keyphrase' => $this->get_meta_string( $post_id, $meta_map['focus_keyphrase'] ),
			'slug'            => $post ? $post->post_name : '',
		);
	}

	private function build_validation_warnings( array $values ): array {
		$warnings = array();
		$title    = (string) ( $values['title'] ?? '' );
		$desc     = (string) ( $values['description'] ?? '' );
		$focus    = (string) ( $values['focus_keyphrase'] ?? '' );

		if ( '' !== $title && strlen( $title ) > 65 ) {
			$warnings[] = 'SEO title is longer than 65 characters.';
		}

		if ( '' !== $desc && strlen( $desc ) < 120 ) {
			$warnings[] = 'Meta description is shorter than 120 characters.';
		}

		if ( strlen( $desc ) > 160 ) {
			$warnings[] = 'Meta description is longer than 160 characters.';
		}

		if ( '' === $focus ) {
			$warnings[] = 'Focus keyphrase is empty.';
		}

		return $warnings;
	}

	private function is_yoast_detected( int $post_id ): bool {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\WPSEO_Meta' ) || function_exists( 'wpseo_init' ) ) {
			return true;
		}

		foreach ( self::YOAST_FIELDS as $meta_key ) {
			if ( '' !== $this->get_meta_string( $post_id, $meta_key ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_rank_math_detected( int $post_id ): bool {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) || class_exists( '\RankMath\Helper' ) ) {
			return true;
		}

		foreach ( self::RANK_MATH_FIELDS as $meta_key ) {
			if ( '' !== $this->get_meta_string( $post_id, $meta_key ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_meta_string( int $post_id, string $meta_key ): string {
		$value = get_post_meta( $post_id, $meta_key, true );
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '';
	}
}
