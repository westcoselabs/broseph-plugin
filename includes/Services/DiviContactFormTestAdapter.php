<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attempts a controlled test submission to a Divi Contact Form module
 * discovered on a WordPress page.
 *
 * Divi contact forms submit to wp-admin/admin-ajax.php using:
 *   action              = et_pb_contact_form_submit
 *   et_pb_contactform_submit = et_pb_contactform_{form_index}_{page_id}
 *   token               = wp_create_nonce('et-pb-contact-form-submit')
 *   et_pb_contact_{custom_field_id}_0 = field value   (per field)
 *
 * Field naming convention is derived from the `custom_field_id` attribute of
 * each [et_pb_contact_field] shortcode.  If that attribute is absent the
 * adapter falls back to positional naming (et_pb_contact_0_0, et_pb_contact_1_0, …).
 *
 * The nonce action string ('et-pb-contact-form-submit') is documented in the
 * Divi 4.x ET Builder source.  If Divi changes its nonce scheme the submission
 * will be rejected and this method will honestly return status:'failed'.
 *
 * NOTE: never fakes success.  'submitted' is returned only when Divi responds
 * with {"result":"success"}.
 */
class DiviContactFormTestAdapter {

	// Divi AJAX action for contact form submissions.
	private const DIVI_AJAX_ACTION = 'et_pb_contact_form_submit';

	// Nonce action string used by the Divi 4.x ET Builder contact form module.
	private const DIVI_NONCE_ACTION = 'et-pb-contact-form-submit';

	// POST field prefix for individual form field values.
	private const FIELD_PREFIX = 'et_pb_contact_';

	// Fuzzy label → test_payload key mapping for common Divi field titles.
	private const LABEL_TO_PAYLOAD_KEY = array(
		'name'                 => 'name',
		'your name'            => 'name',
		'full name'            => 'name',
		'first name'           => 'name',
		'email'                => 'email',
		'email address'        => 'email',
		'your email'           => 'email',
		'e-mail'               => 'email',
		'phone'                => 'phone',
		'phone number'         => 'phone',
		'telephone'            => 'phone',
		'mobile'               => 'phone',
		'tel'                  => 'phone',
		'company'              => 'company',
		'company name'         => 'company',
		'organization'         => 'company',
		'business'             => 'company',
		'website'              => 'website',
		'web'                  => 'website',
		'url'                  => 'website',
		'message'              => 'message',
		'your message'         => 'message',
		'comments'             => 'message',
		'comment'              => 'message',
		'inquiry'              => 'message',
		'how can we help'      => 'message',
		'how can we help you'  => 'message',
	);

	public function run_test( array $params, array|\WP_Error $forms_on_page ): array {
		$page_id      = isset( $params['page_id'] ) ? (int) $params['page_id'] : 0;
		$test_payload = is_array( $params['test_payload'] ?? null ) ? $params['test_payload'] : array();
		$warnings     = array();

		// ── Prerequisite checks ───────────────────────────────────────────────

		if ( is_wp_error( $forms_on_page ) ) {
			return $this->unsupported( $forms_on_page->get_error_message(), $page_id, null, array() );
		}

		$divi_forms = array_values(
			array_filter(
				$forms_on_page['forms'] ?? array(),
				static fn( $f ) => 'divi_contact_form' === ( $f['form_type'] ?? '' )
			)
		);

		if ( empty( $divi_forms ) ) {
			return $this->unsupported(
				'No Divi contact form detected on page ' . $page_id . '. Run GET /forms/page/' . $page_id . ' to verify.',
				$page_id, null, array()
			);
		}

		$form        = $divi_forms[0];
		$form_title  = $form['title'] ?? 'Divi Contact Form';
		$fields      = $form['detected_fields'] ?? array();

		if ( ! has_action( 'wp_ajax_nopriv_' . self::DIVI_AJAX_ACTION ) ) {
			return $this->unsupported(
				'Divi contact form AJAX handler (wp_ajax_nopriv_' . self::DIVI_AJAX_ACTION . ') is not registered. Ensure the Divi theme or ET Builder is active.',
				$page_id, $form_title, array()
			);
		}

		if ( empty( $fields ) ) {
			return $this->unsupported(
				'No field definitions could be parsed from the Divi contact form on page ' . $page_id . '. The form shortcode may be missing field_title attributes.',
				$page_id, $form_title, array()
			);
		}

		// ── Field mapping ─────────────────────────────────────────────────────

		$mapping = $this->map_fields( $fields, $test_payload );

		if ( is_wp_error( $mapping ) ) {
			return $this->unsupported( $mapping->get_error_message(), $page_id, $form_title, $warnings );
		}

		[ 'post_fields' => $post_fields, 'masked' => $masked, 'field_warnings' => $field_warnings ] = $mapping;
		$warnings = array_merge( $warnings, $field_warnings );

		// ── Build submission ──────────────────────────────────────────────────

		$form_index    = 0;
		$nonce         = wp_create_nonce( self::DIVI_NONCE_ACTION );
		$page_url      = get_permalink( $page_id ) ?: home_url();
		$page_path     = parse_url( $page_url, PHP_URL_PATH ) ?? '/';

		$post_data = array_merge(
			$post_fields,
			array(
				'action'                     => self::DIVI_AJAX_ACTION,
				'et_pb_contactform_submit'   => 'et_pb_contactform_' . $form_index . '_' . $page_id,
				'token'                      => $nonce,
				'_wp_http_referer'           => $page_path,
			)
		);

		// ── Loopback request ──────────────────────────────────────────────────

		$http_response = wp_safe_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'body'      => $post_data,
				'timeout'   => 15,
				'headers'   => array(
					'Referer'          => $page_url,
					'X-Requested-With' => 'XMLHttpRequest',
				),
				// Default true; sites with self-signed certs can override via filter.
				'sslverify' => (bool) apply_filters( 'broseph_divi_form_test_sslverify', true ),
			)
		);

		if ( is_wp_error( $http_response ) ) {
			return array(
				'status'                  => 'failed',
				'test_supported'          => true,
				'form_type'               => 'divi_contact_form',
				'page_id'                 => $page_id,
				'form_title'              => $form_title,
				'submitted_fields_masked' => $masked,
				'response_message'        => 'Loopback HTTP request failed: ' . $http_response->get_error_message(),
				'mail_check_hint'         => 'Check /mail/logs/recent or recipient inbox. The loopback connection may need fixing (see Site Health > Loopback Requests).',
				'warnings'                => $warnings,
			);
		}

		// ── Parse Divi response ───────────────────────────────────────────────

		$http_code   = (int) wp_remote_retrieve_response_code( $http_response );
		$raw_body    = wp_remote_retrieve_body( $http_response );
		$data        = json_decode( $raw_body, true );
		$divi_result = is_array( $data ) ? ( $data['result'] ?? null ) : null;
		$divi_error  = is_array( $data ) ? ( $data['error'] ?? $data['message'] ?? null ) : null;

		if ( 'success' === $divi_result ) {
			return array(
				'status'                  => 'submitted',
				'test_supported'          => true,
				'form_type'               => 'divi_contact_form',
				'page_id'                 => $page_id,
				'form_title'              => $form_title,
				'submitted_fields_masked' => $masked,
				'response_message'        => 'Divi accepted the form submission.',
				'mail_check_hint'         => 'Check /mail/logs/recent or the configured recipient inbox to confirm email delivery.',
				'warnings'                => $warnings,
			);
		}

		if ( null !== $divi_result ) {
			return array(
				'status'                  => 'failed',
				'test_supported'          => true,
				'form_type'               => 'divi_contact_form',
				'page_id'                 => $page_id,
				'form_title'              => $form_title,
				'submitted_fields_masked' => $masked,
				'response_message'        => 'Divi rejected the submission: ' . ( $divi_error ?? $divi_result ),
				'mail_check_hint'         => 'Verify SMTP configuration via POST /mail/test, and check the Divi form notification email settings.',
				'warnings'                => $warnings,
			);
		}

		// Non-JSON or unexpected response.
		$warnings[] = 'Divi returned an unparseable response (HTTP ' . $http_code . '). Raw body not logged.';

		return array(
			'status'                  => 'failed',
			'test_supported'          => true,
			'form_type'               => 'divi_contact_form',
			'page_id'                 => $page_id,
			'form_title'              => $form_title,
			'submitted_fields_masked' => $masked,
			'response_message'        => 'Could not parse Divi response (HTTP ' . $http_code . '). This may indicate a nonce mismatch or a Divi version difference.',
			'mail_check_hint'         => 'Check /mail/logs/recent to see if any email was triggered. Also try POST /mail/test.',
			'warnings'                => $warnings,
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Maps detected Divi fields to POST keys and test_payload values.
	 *
	 * Divi's POST key for a field is built as:
	 *   et_pb_contact_{custom_field_id}_{form_index}
	 *
	 * If custom_field_id is absent the field's position index is used.
	 *
	 * @return array{post_fields:array,masked:array,field_warnings:array}|\WP_Error
	 */
	private function map_fields( array $detected_fields, array $test_payload ): array|\WP_Error {
		$post_fields    = array();
		$masked         = array();
		$field_warnings = array();
		$unmapped_req   = array();

		foreach ( $detected_fields as $index => $field ) {
			$label    = strtolower( trim( $field['label'] ?? '' ) );
			$type     = $field['type'] ?? 'input';
			$required = (bool) ( $field['required'] ?? false );

			// Prefer custom_field_id → field_id → positional.
			$field_slug = null;
			if ( ! empty( $field['custom_field_id'] ) ) {
				$field_slug = strtolower( $field['custom_field_id'] );
			} elseif ( ! empty( $field['field_id'] ) ) {
				$field_slug = strtolower( str_replace( ' ', '_', $field['field_id'] ) );
			} else {
				$field_slug = (string) $index;
			}

			$post_key = self::FIELD_PREFIX . $field_slug . '_0';

			$value = $this->find_payload_value( $label, $type, $test_payload );

			if ( null === $value ) {
				if ( 'checkbox' === $type ) {
					// Unchecked checkbox: omit from submission (standard HTML form behaviour).
					continue;
				}
				if ( $required ) {
					$unmapped_req[] = $field['label'] ?? $field_slug;
				} else {
					$field_warnings[] = "Optional field '{$field['label']}' not mapped; omitting from test submission.";
				}
				continue;
			}

			if ( 'checkbox' === $type && in_array( strtolower( (string) $value ), array( '0', 'false', 'no', '' ), true ) ) {
				continue; // Unchecked.
			}

			$post_fields[ $post_key ] = $value;
			$masked[ $post_key ]      = $this->mask_value( $value, $type );
		}

		if ( ! empty( $unmapped_req ) ) {
			return new \WP_Error(
				'broseph_unmapped_fields',
				'Required field(s) cannot be mapped from test_payload: ' . implode( ', ', $unmapped_req )
				. '. Add matching keys (name/email/phone/company/website/message) to test_payload.'
			);
		}

		return array(
			'post_fields'    => $post_fields,
			'masked'         => $masked,
			'field_warnings' => $field_warnings,
		);
	}

	/**
	 * Finds a test payload value for a given field label and type.
	 * First tries an exact key match, then a fuzzy label match via LABEL_TO_PAYLOAD_KEY.
	 */
	private function find_payload_value( string $label, string $type, array $payload ): ?string {
		// Exact payload key match (case-insensitive).
		foreach ( $payload as $key => $val ) {
			if ( strtolower( $key ) === $label ) {
				return (string) $val;
			}
		}

		// Fuzzy label → canonical payload key.
		$payload_key = self::LABEL_TO_PAYLOAD_KEY[ $label ] ?? null;
		if ( null !== $payload_key && isset( $payload[ $payload_key ] ) ) {
			return (string) $payload[ $payload_key ];
		}

		// Type-based fallback (e.g. an unlabelled email field).
		if ( 'email' === $type && isset( $payload['email'] ) ) {
			return (string) $payload['email'];
		}
		if ( 'checkbox' === $type ) {
			foreach ( array( 'sms_disclaimer', 'consent', 'agree', 'terms', 'disclaimer' ) as $ck ) {
				if ( isset( $payload[ $ck ] ) ) {
					return (string) $payload[ $ck ];
				}
			}
			return '1'; // Default: checked.
		}

		return null;
	}

	private function unsupported( string $reason, int $page_id, ?string $form_title, array $warnings ): array {
		return array(
			'status'                  => 'unsupported',
			'test_supported'          => false,
			'form_type'               => 'divi_contact_form',
			'page_id'                 => $page_id,
			'form_title'              => $form_title,
			'submitted_fields_masked' => array(),
			'response_message'        => $reason,
			'mail_check_hint'         => 'Use POST /broseph/v1/mail/test to verify SMTP/mail delivery independently.',
			'warnings'                => $warnings,
		);
	}

	/**
	 * Returns a masked representation of a field value safe for logging/response.
	 * Full values are never stored or logged.
	 */
	private function mask_value( string $value, string $type ): string {
		if ( 'email' === $type ) {
			$parts = explode( '@', $value, 2 );
			if ( 2 === count( $parts ) ) {
				return ( $parts[0][0] ?? '*' ) . '***@' . $parts[1];
			}
		}
		if ( 'checkbox' === $type ) {
			return $value ? 'checked' : 'unchecked';
		}
		$len = mb_strlen( $value );
		if ( $len <= 2 ) {
			return str_repeat( '*', $len );
		}
		return mb_substr( $value, 0, 1 ) . '***';
	}
}
