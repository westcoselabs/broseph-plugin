<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormService {

	public function get_all_forms(): array {
		$forms = array_merge(
			$this->discover_cf7_forms(),
			$this->discover_wpforms_forms(),
			$this->discover_gravity_forms(),
			$this->discover_fluent_forms(),
			$this->discover_ninja_forms(),
			$this->scan_divi_contact_forms()
		);

		return array(
			'forms' => $forms,
			'count' => count( $forms ),
		);
	}

	public function get_forms_on_page( int $page_id ): array|\WP_Error {
		$post = get_post( $page_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new \WP_Error( 'broseph_not_found', 'Page not found.', array( 'status' => 404 ) );
		}

		$forms = $this->extract_all_forms_from_post( $post );

		return array(
			'page_id'    => $page_id,
			'page_title' => $post->post_title,
			'page_url'   => get_permalink( $post ),
			'forms'      => $forms,
			'count'      => count( $forms ),
		);
	}

	public function get_test_info( array $params ): array {
		$form_type = isset( $params['form_type'] ) ? sanitize_text_field( (string) $params['form_type'] ) : null;
		$form_id   = isset( $params['form_id'] ) ? (int) $params['form_id'] : null;

		return array(
			'status'          => 'unsupported',
			'test_supported'  => false,
			'form_type'       => $form_type,
			'form_id'         => $form_id,
			'message'         => 'Direct form submission testing is not implemented for this form type yet. Use /mail/test to verify SMTP/mail delivery.',
			'next_steps'      => array(
				'Use POST /broseph/v1/mail/test to verify wp_mail() delivery.',
				'Check form plugin settings to confirm notification email address.',
			),
		);
	}

	// ── Plugin discovery ──────────────────────────────────────────────────────

	private function discover_cf7_forms(): array {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}

		$cf7_forms = \WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) );
		if ( ! is_array( $cf7_forms ) ) {
			return array();
		}

		$results = array();
		foreach ( $cf7_forms as $cf7 ) {
			if ( ! ( $cf7 instanceof \WPCF7_ContactForm ) ) {
				continue;
			}
			$pages = $this->find_pages_using_shortcode( 'contact-form-7', (string) $cf7->id() );
			if ( empty( $pages ) ) {
				$results[] = $this->make_form_entry(
					'contact_form_7', $cf7->id(), null, null, null,
					$cf7->title(), array(),
					array( 'CF7 form registered but not found on any scanned page.' )
				);
			} else {
				foreach ( $pages as $pg ) {
					$results[] = $this->make_form_entry(
						'contact_form_7', $cf7->id(),
						$pg['page_id'], $pg['page_title'], $pg['page_url'],
						$cf7->title(), array(), array()
					);
				}
			}
		}

		return $results;
	}

	private function discover_wpforms_forms(): array {
		if ( ! class_exists( 'WPForms\WPForms' ) && ! function_exists( 'wpforms' ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'wpforms',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			)
		);

		$results = array();
		foreach ( $query->posts as $form_post ) {
			if ( ! ( $form_post instanceof \WP_Post ) ) {
				continue;
			}
			$pages = $this->find_pages_using_shortcode( 'wpforms', (string) $form_post->ID );
			if ( empty( $pages ) ) {
				$results[] = $this->make_form_entry(
					'wpforms', $form_post->ID, null, null, null,
					$form_post->post_title, array(),
					array( 'WPForms form registered but not found on any scanned page.' )
				);
			} else {
				foreach ( $pages as $pg ) {
					$results[] = $this->make_form_entry(
						'wpforms', $form_post->ID,
						$pg['page_id'], $pg['page_title'], $pg['page_url'],
						$form_post->post_title, array(), array()
					);
				}
			}
		}

		return $results;
	}

	private function discover_gravity_forms(): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$gf_forms = \GFAPI::get_forms();
		if ( ! is_array( $gf_forms ) ) {
			return array();
		}

		$results = array();
		foreach ( $gf_forms as $gf ) {
			if ( ! is_array( $gf ) ) {
				continue;
			}
			$form_id = (int) ( $gf['id'] ?? 0 );
			if ( $form_id <= 0 ) {
				continue;
			}
			$title = (string) ( $gf['title'] ?? "GF #{$form_id}" );

			$pages = array_merge(
				$this->find_pages_using_shortcode( 'gravityforms', (string) $form_id ),
				$this->find_pages_using_shortcode( 'gravityform', (string) $form_id )
			);

			if ( empty( $pages ) ) {
				$results[] = $this->make_form_entry(
					'gravity_forms', $form_id, null, null, null,
					$title, array(),
					array( 'Gravity Forms form registered but not found on any scanned page.' )
				);
			} else {
				foreach ( $pages as $pg ) {
					$results[] = $this->make_form_entry(
						'gravity_forms', $form_id,
						$pg['page_id'], $pg['page_title'], $pg['page_url'],
						$title, array(), array()
					);
				}
			}
		}

		return $results;
	}

	private function discover_fluent_forms(): array {
		if ( ! class_exists( 'FluentForm\App\Modules\Form\FormManager' )
			&& ! function_exists( 'wpFluentForm' )
			&& ! class_exists( 'FluentForm' ) ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fluentform_forms';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT `id`, `title` FROM `{$table}` WHERE `status` = 'published' LIMIT 50",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$form_id = (int) $row['id'];
			$title   = (string) ( $row['title'] ?? "Fluent Form #{$form_id}" );

			$pages = array_merge(
				$this->find_pages_using_shortcode( 'fluentform', (string) $form_id ),
				$this->find_pages_using_shortcode( 'fluentforms', (string) $form_id )
			);

			if ( empty( $pages ) ) {
				$results[] = $this->make_form_entry(
					'fluent_forms', $form_id, null, null, null,
					$title, array(),
					array( 'Fluent Forms form registered but not found on any scanned page.' )
				);
			} else {
				foreach ( $pages as $pg ) {
					$results[] = $this->make_form_entry(
						'fluent_forms', $form_id,
						$pg['page_id'], $pg['page_title'], $pg['page_url'],
						$title, array(), array()
					);
				}
			}
		}

		return $results;
	}

	private function discover_ninja_forms(): array {
		if ( ! class_exists( 'Ninja_Forms' ) && ! function_exists( 'ninja_forms' ) ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'nf_form',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			)
		);

		$results = array();
		foreach ( $query->posts as $form_post ) {
			if ( ! ( $form_post instanceof \WP_Post ) ) {
				continue;
			}
			$pages = array_merge(
				$this->find_pages_using_shortcode( 'ninja_forms', (string) $form_post->ID ),
				$this->find_pages_using_shortcode( 'ninja_form', (string) $form_post->ID )
			);

			if ( empty( $pages ) ) {
				$results[] = $this->make_form_entry(
					'ninja_forms', $form_post->ID, null, null, null,
					$form_post->post_title, array(),
					array( 'Ninja Forms form registered but not found on any scanned page.' )
				);
			} else {
				foreach ( $pages as $pg ) {
					$results[] = $this->make_form_entry(
						'ninja_forms', $form_post->ID,
						$pg['page_id'], $pg['page_title'], $pg['page_url'],
						$form_post->post_title, array(), array()
					);
				}
			}
		}

		return $results;
	}

	// ── Divi scanning ─────────────────────────────────────────────────────────

	private function scan_divi_contact_forms(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status IN ('publish','draft')
				   AND post_content LIKE %s
				 LIMIT 200",
				'page',
				'%' . $wpdb->esc_like( '[et_pb_contact_form' ) . '%'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results = array_merge(
				$results,
				$this->extract_divi_contact_forms(
					(int) $row['ID'],
					(string) $row['post_title'],
					(string) $row['post_content']
				)
			);
		}

		return $results;
	}

	private function extract_divi_contact_forms( int $page_id, string $page_title, string $content ): array {
		$page_url = get_permalink( $page_id );
		$results  = array();

		$pattern = '/\[et_pb_contact_form([^\]]*)\](.*?)\[\/et_pb_contact_form\]/s';

		if ( ! preg_match_all( $pattern, $content, $form_matches, PREG_SET_ORDER ) ) {
			$results[] = $this->make_form_entry(
				'divi_contact_form', null, $page_id, $page_title, $page_url,
				'Divi Contact Form', array(),
				array( 'et_pb_contact_form detected but block structure could not be parsed.' )
			);
			return $results;
		}

		foreach ( $form_matches as $match ) {
			$attrs_str  = $match[1];
			$inner      = $match[2];
			$form_title = null;
			$warnings   = array();

			if ( preg_match( '/\btitle="([^"]*)"/', $attrs_str, $tm ) ) {
				$form_title = $tm[1];
			}

			$fields = array();
			if ( preg_match_all( '/\[et_pb_contact_field([^\]\/]*)/', $inner, $fm, PREG_SET_ORDER ) ) {
				foreach ( $fm as $field_match ) {
					$fa          = $field_match[1];
					$field_label = null;
					$field_type  = null;
					$required    = false;

					if ( preg_match( '/\bfield_title="([^"]*)"/', $fa, $ftm ) ) {
						$field_label = $ftm[1];
					}
					if ( preg_match( '/\bfield_type="([^"]*)"/', $fa, $ftype ) ) {
						$field_type = $ftype[1];
					}
					if ( str_contains( $fa, 'required_mark="on"' ) ) {
						$required = true;
					}

					if ( $field_label ) {
						$fields[] = array(
							'label'    => $field_label,
							'type'     => $field_type,
							'required' => $required,
						);
					}
				}
			} else {
				$warnings[] = 'Could not parse field definitions from inner content.';
			}

			$results[] = $this->make_form_entry(
				'divi_contact_form', null, $page_id, $page_title, $page_url,
				$form_title ?? 'Divi Contact Form',
				$fields,
				$warnings
			);
		}

		return $results;
	}

	// ── Single-page extraction ────────────────────────────────────────────────

	private function extract_all_forms_from_post( \WP_Post $post ): array {
		$content    = $post->post_content;
		$page_id    = $post->ID;
		$page_title = $post->post_title;
		$page_url   = get_permalink( $post );
		$forms      = array();

		// CF7
		if ( class_exists( 'WPCF7_ContactForm' ) && str_contains( $content, '[contact-form-7' ) ) {
			if ( preg_match_all( '/\[contact-form-7[^\]]*id=["\']?(\d+)["\']?/', $content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $cf7_id ) {
					$cf7     = \WPCF7_ContactForm::get_instance( (int) $cf7_id );
					$forms[] = $this->make_form_entry(
						'contact_form_7', (int) $cf7_id, $page_id, $page_title, $page_url,
						$cf7 instanceof \WPCF7_ContactForm ? $cf7->title() : "CF7 Form #{$cf7_id}",
						array(), array()
					);
				}
			}
		}

		// WPForms
		if ( str_contains( $content, '[wpforms' ) ) {
			if ( preg_match_all( '/\[wpforms[^\]]*id=["\']?(\d+)["\']?/', $content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $wpf_id ) {
					$fp      = get_post( (int) $wpf_id );
					$forms[] = $this->make_form_entry(
						'wpforms', (int) $wpf_id, $page_id, $page_title, $page_url,
						$fp instanceof \WP_Post ? $fp->post_title : "WPForms #{$wpf_id}",
						array(), array()
					);
				}
			}
		}

		// Gravity Forms
		if ( class_exists( 'GFAPI' ) && (
			str_contains( $content, '[gravityforms' ) ||
			str_contains( $content, '[gravityform ' )
		) ) {
			if ( preg_match_all( '/\[gravityforms?\s[^\]]*id=["\']?(\d+)["\']?/', $content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $gf_id ) {
					$gf       = \GFAPI::get_form( (int) $gf_id );
					$gf_title = is_array( $gf ) ? (string) ( $gf['title'] ?? "GF #{$gf_id}" ) : "GF #{$gf_id}";
					$forms[]  = $this->make_form_entry(
						'gravity_forms', (int) $gf_id, $page_id, $page_title, $page_url,
						$gf_title, array(), array()
					);
				}
			}
		}

		// Fluent Forms
		if ( ( class_exists( 'FluentForm\App\Modules\Form\FormManager' ) || function_exists( 'wpFluentForm' ) )
			&& ( str_contains( $content, '[fluentform' ) || str_contains( $content, '[fluentforms' ) ) ) {
			if ( preg_match_all( '/\[fluentforms?\s[^\]]*id=["\']?(\d+)["\']?/', $content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $ff_id ) {
					$forms[] = $this->make_form_entry(
						'fluent_forms', (int) $ff_id, $page_id, $page_title, $page_url,
						"Fluent Form #{$ff_id}", array(), array()
					);
				}
			}
		}

		// Ninja Forms
		if ( ( class_exists( 'Ninja_Forms' ) || function_exists( 'ninja_forms' ) )
			&& ( str_contains( $content, '[ninja_forms' ) || str_contains( $content, '[ninja_form ' ) ) ) {
			if ( preg_match_all( '/\[ninja_forms?\s[^\]]*id=["\']?(\d+)["\']?/', $content, $m ) ) {
				foreach ( array_unique( $m[1] ) as $nf_id ) {
					$fp      = get_post( (int) $nf_id );
					$forms[] = $this->make_form_entry(
						'ninja_forms', (int) $nf_id, $page_id, $page_title, $page_url,
						$fp instanceof \WP_Post ? $fp->post_title : "Ninja Form #{$nf_id}",
						array(), array()
					);
				}
			}
		}

		// Divi Contact Forms
		if ( str_contains( $content, '[et_pb_contact_form' ) ) {
			$divi  = $this->extract_divi_contact_forms( $page_id, $page_title, $content );
			$forms = array_merge( $forms, $divi );
		}

		return $forms;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Broad DB query for pages containing the shortcode tag, then confirms
	 * the specific form ID via regex to avoid false positives.
	 */
	private function find_pages_using_shortcode( string $tag, string $form_id ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status IN ('publish','draft')
				   AND post_content LIKE %s
				 LIMIT 200",
				'page',
				$like
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$results = array();
		$rx      = '/\[' . preg_quote( $tag, '/' ) . '[^\]]*id=["\']?' . preg_quote( $form_id, '/' ) . '["\']?/';

		foreach ( $rows as $row ) {
			if ( preg_match( $rx, (string) $row['post_content'] ) ) {
				$results[] = array(
					'page_id'    => (int) $row['ID'],
					'page_title' => (string) $row['post_title'],
					'page_url'   => get_permalink( (int) $row['ID'] ),
				);
			}
		}

		return $results;
	}

	private function make_form_entry(
		string $form_type,
		?int $form_id,
		?int $page_id,
		?string $page_title,
		?string $page_url,
		?string $title,
		array $detected_fields,
		array $warnings
	): array {
		return array(
			'form_type'          => $form_type,
			'form_id'            => $form_id,
			'page_id'            => $page_id,
			'page_title'         => $page_title,
			'page_url'           => $page_url,
			'title'              => $title,
			'detected_fields'    => $detected_fields,
			'test_supported'     => false,
			'mail_test_supported' => true,
			'warnings'           => $warnings,
		);
	}
}
