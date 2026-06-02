<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiviService {

	// Divi layout shortcodes (structural).
	private const LAYOUT_TAGS = array( 'et_pb_section', 'et_pb_row', 'et_pb_column' );

	// Divi content module shortcodes we summarize.
	private const CONTENT_TAGS = array( 'et_pb_text', 'et_pb_code', 'et_pb_button', 'et_pb_image' );

	public function is_divi_active(): bool {
		$theme = wp_get_theme();
		if ( 'Divi' === $theme->get( 'Name' ) || 'Divi' === $theme->get( 'Template' ) ) {
			return true;
		}
		if ( defined( 'ET_BUILDER_VERSION' ) || class_exists( 'ET_Builder_Module' ) ) {
			return true;
		}
		return false;
	}

	public function is_divi_page( string $content ): bool {
		return str_contains( $content, '[et_pb_section' );
	}

	public function get_page_summary( int $page_id ): array {
		$post = get_post( $page_id );

		if ( ! $post ) {
			return array(
				'page_id'      => $page_id,
				'is_divi_page' => false,
				'module_count' => 0,
				'modules'      => array(),
				'warnings'     => array( 'Page not found.' ),
			);
		}

		$content    = $post->post_content;
		$is_divi    = $this->is_divi_page( $content );
		$warnings   = array();

		if ( ! $is_divi ) {
			return array(
				'page_id'      => $page_id,
				'is_divi_page' => false,
				'module_count' => 0,
				'modules'      => array(),
				'warnings'     => array(),
			);
		}

		$parsed  = $this->parse_content_modules( $content, $warnings );

		return array(
			'page_id'      => $page_id,
			'is_divi_page' => true,
			'module_count' => count( $parsed ),
			'modules'      => $parsed,
			'warnings'     => $warnings,
		);
	}

	public function parse_content_modules( string $content, array &$warnings = array() ): array {
		$modules = array();

		foreach ( self::CONTENT_TAGS as $tag ) {
			$escaped = preg_quote( $tag, '/' );
			$pattern = '/\[' . $escaped . '([^\]]*)\](.*?)\[\/' . $escaped . '\]/s';
			$offset  = 0;

			while ( preg_match( $pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				$start = (int) $m[0][1];
				$inner = (string) $m[2][0];
				$end   = $start + strlen( (string) $m[0][0] );

				$module = array(
					'module_index'           => 0, // re-indexed below
					'module_type'            => str_replace( 'et_pb_', '', $tag ),
					'shortcode_name'         => $tag,
					'text_preview'           => null,
					'code_preview'           => null,
					'has_gitpress_shortcode' => str_contains( $inner, '[divi_github_content' ),
					'start_offset'           => $start,
					'end_offset'             => $end,
				);

				if ( 'et_pb_text' === $tag ) {
					$plain               = wp_strip_all_tags( strip_shortcodes( $inner ) );
					$module['text_preview'] = mb_substr( trim( $plain ), 0, 200 );
				} elseif ( 'et_pb_code' === $tag ) {
					$module['code_preview'] = mb_substr( trim( $inner ), 0, 200 );
				}

				$modules[] = $module;
				$offset    = $end;
			}
		}

		// Sort by position in the document.
		usort(
			$modules,
			static fn( $a, $b ) => $a['start_offset'] <=> $b['start_offset']
		);

		foreach ( $modules as $i => &$module ) {
			$module['module_index'] = $i;
		}
		unset( $module );

		return $modules;
	}

	/**
	 * Validates raw code module content before it is saved into a Divi Code Module.
	 * Blocks PHP and (optionally) script tags.
	 */
	public function validate_code_module_content( string $content ): array {
		$errors   = array();
		$warnings = array();

		if ( preg_match( '/(<\?(?:php|=)|<%)/', $content ) ) {
			$errors[] = 'PHP code is not allowed in code module content.';
		}

		if ( preg_match( '/<script[\s>]/i', $content ) ) {
			if ( ! (bool) get_option( 'broseph_allow_js_snippets', false ) ) {
				$errors[] = 'Script tags are not allowed. Enable Allow JS Snippets in Broseph Settings to permit them.';
			} else {
				$warnings[] = 'Script tags detected. Allowed by JS snippets setting.';
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Append a GitPress shortcode inside a new et_pb_code module at the end of
	 * the page's Divi content. Returns the modified content string.
	 */
	public function append_code_module( string $content, string $gitpress_shortcode ): string {
		$snippet = "\n[et_pb_section fb_built=\"1\"][et_pb_row][et_pb_column type=\"4_4\"][et_pb_code]"
			. $gitpress_shortcode
			. "[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]";

		return $content . $snippet;
	}

	/**
	 * Insert a code module after the module at $module_index. Returns modified
	 * content or null if the position cannot be safely determined.
	 */
	public function insert_code_module_after( string $content, int $module_index, string $gitpress_shortcode ): ?string {
		$warnings = array();
		$modules  = $this->parse_content_modules( $content, $warnings );

		if ( ! empty( $warnings ) ) {
			return null;
		}

		$target = null;
		foreach ( $modules as $module ) {
			if ( $module['module_index'] === $module_index ) {
				$target = $module;
				break;
			}
		}

		if ( null === $target || null === $target['end_offset'] ) {
			return null;
		}

		$snippet = "\n[et_pb_code]" . $gitpress_shortcode . "[/et_pb_code]";
		$end     = (int) $target['end_offset'];

		return substr( $content, 0, $end ) . $snippet . substr( $content, $end );
	}
}
