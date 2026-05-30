<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitPressIntegration {

	private const SHORTCODE       = 'divi_github_content';
	private const VALID_FORMATS   = array( 'html', 'markdown', 'text', 'code', 'raw' );

	// Slug fragments used to identify the GitPress plugin file.
	private const PLUGIN_SLUGS = array( 'gitpress', 'divi-github-sync', 'divi_github' );

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
