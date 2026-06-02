<?php
declare( strict_types=1 );

namespace Broseph\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormsController extends BaseController {

	private \Broseph\Services\FormService $forms;
	private \Broseph\Services\DiviContactFormTestAdapter $divi_form_test;
	private \Broseph\Services\MailLogService $mail_log;
	private \Broseph\Services\MailTestService $mail_test;
	private \Broseph\Services\PermissionsService $permissions;

	public function __construct(
		\Broseph\Auth\RequestSigner $signer,
		\Broseph\Logging\ActionLogger $logger,
		\Broseph\Services\FormService $forms,
		\Broseph\Services\DiviContactFormTestAdapter $divi_form_test,
		\Broseph\Services\MailLogService $mail_log,
		\Broseph\Services\MailTestService $mail_test,
		\Broseph\Services\PermissionsService $permissions
	) {
		parent::__construct( $signer, $logger );
		$this->forms          = $forms;
		$this->divi_form_test = $divi_form_test;
		$this->mail_log       = $mail_log;
		$this->mail_test      = $mail_test;
		$this->permissions    = $permissions;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/forms',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/forms/page/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_page' ),
				'permission_callback' => array( $this, 'require_signed' ),
				'args'                => array(
					'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/forms/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);

		register_rest_route(
			$namespace,
			'/forms/full-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_full_test' ),
				'permission_callback' => array( $this, 'require_signed' ),
			)
		);
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	public function handle_list( \WP_REST_Request $request ): \WP_REST_Response {
		$this->logger->log(
			'accepted',
			array(
				'task_type' => 'forms_list',
				'endpoint'  => $request->get_route(),
				'method'    => $request->get_method(),
			)
		);

		return new \WP_REST_Response( $this->forms->get_all_forms(), 200 );
	}

	public function handle_page( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->forms->get_forms_on_page( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->logger->log(
			'accepted',
			array(
				'task_type'   => 'forms_page_scan',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'object_type' => 'page',
				'object_id'   => $id,
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	public function handle_test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $this->permissions->can_submit_form_tests() ) {
			return $this->permission_denied( 'can_submit_form_tests' );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$form_type = (string) ( $body['form_type'] ?? '' );
		$page_id   = isset( $body['page_id'] ) ? (int) $body['page_id'] : 0;

		if ( 'divi_contact_form' === $form_type && $page_id > 0 ) {
			$forms_on_page = $this->forms->get_forms_on_page( $page_id );
			$result        = $this->divi_form_test->run_test( $body, $forms_on_page );
		} else {
			$result = $this->forms->get_test_info( $body );
		}

		// Never log body content or full email addresses.
		$this->logger->log(
			'accepted',
			array(
				'task_type'   => 'form_test_requested',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'object_type' => 'page',
				'object_id'   => $page_id ?: null,
				'message'     => sprintf(
					'form_test: form_type=%s page_id=%d status=%s',
					$form_type ?: 'unknown',
					$page_id,
					$result['status'] ?? 'unknown'
				),
			)
		);

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Full orchestrated test: form submission + optional mail-log delivery check.
	 *
	 * Payload:
	 *   form_type              string  — currently only 'divi_contact_form'
	 *   page_id                int     — required
	 *   test_payload           array   — field values for the form
	 *   fallback_mail_test     bool    — if true and form test is unsupported, run MailTestService
	 *   fallback_to            string  — recipient for fallback mail test (defaults to admin_email)
	 *   mail_log_window_seconds int    — how many seconds back to look in mail logs (10–300, default 60)
	 */
	public function handle_full_test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// At least one of form tests or mail fallback must be permitted.
		$can_form = $this->permissions->can_submit_form_tests();
		$can_mail = $this->permissions->can_send_mail_tests() && $this->permissions->can_use_form_mail_fallback();
		if ( ! $can_form && ! $can_mail ) {
			return $this->permission_denied( 'can_submit_form_tests' );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'broseph_bad_request', 'JSON body required.', array( 'status' => 400 ) );
		}

		$form_type          = (string) ( $body['form_type'] ?? 'divi_contact_form' );
		$page_id            = isset( $body['page_id'] ) ? (int) $body['page_id'] : 0;
		$fallback_mail_test = (bool) ( $body['fallback_mail_test'] ?? false );
		$log_window_secs    = min( max( 10, (int) ( $body['mail_log_window_seconds'] ?? 60 ) ), 300 );
		$warnings           = array();

		// ── Step 1: form submission ───────────────────────────────────────────

		$submission_result = null;
		$delivery_status   = 'not_checked';
		$mail_log_source   = null;
		$fallback_used     = false;

		if ( 'divi_contact_form' === $form_type && $page_id > 0 ) {
			$forms_on_page     = $this->forms->get_forms_on_page( $page_id );
			$submission_result = $this->divi_form_test->run_test( $body, $forms_on_page );
			$sub_status        = $submission_result['status'] ?? 'unknown';

			if ( 'submitted' === $sub_status ) {
				// ── Step 2: mail log delivery check ───────────────────────────
				[ 'delivery_status' => $delivery_status, 'source_plugin' => $mail_log_source ]
					= $this->check_mail_delivery( $log_window_secs );

			} elseif ( 'unsupported' === $sub_status && $fallback_mail_test
				&& $this->permissions->can_send_mail_tests()
				&& $this->permissions->can_use_form_mail_fallback() ) {
				// ── Step 2 alt: fallback wp_mail() test ──────────────────────
				$fallback_result = $this->mail_test->send_test( array(
					'to'               => $body['fallback_to'] ?? '',
					'label'            => 'Broseph form full-test fallback',
					'include_site_info' => true,
				) );
				$submission_result = $fallback_result;
				$fallback_used     = true;
				$delivery_status   = 'not_checked';
				$warnings[]        = 'Form test unsupported; fallback wp_mail() test was run instead.';

			} else {
				$delivery_status = 'not_applicable';
			}
		} elseif ( $fallback_mail_test ) {
			$fallback_result = $this->mail_test->send_test( array(
				'to'               => $body['fallback_to'] ?? '',
				'label'            => 'Broseph form full-test fallback',
				'include_site_info' => true,
			) );
			$submission_result = $fallback_result;
			$fallback_used     = true;
			$warnings[]        = 'No divi_contact_form + page_id provided; ran fallback wp_mail() test.';
		} else {
			$submission_result = $this->forms->get_test_info( $body );
			$delivery_status   = 'not_applicable';
		}

		// ── Step 3: compute final result ─────────────────────────────────────

		$final_result = $this->compute_final_result( $submission_result, $delivery_status, $fallback_used );

		// ── Step 4: log — never include body content or full email addresses ──

		$this->logger->log(
			'accepted',
			array(
				'task_type'   => 'form_full_test',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'object_type' => 'page',
				'object_id'   => $page_id ?: null,
				'message'     => sprintf(
					'form_full_test: form_type=%s page_id=%d sub_status=%s delivery=%s final=%s fallback=%s',
					$form_type,
					$page_id,
					$submission_result['status'] ?? 'unknown',
					$delivery_status,
					$final_result,
					$fallback_used ? 'yes' : 'no'
				),
			)
		);

		// ── Step 5: build response ────────────────────────────────────────────

		$all_warnings = array_merge( $warnings, $submission_result['warnings'] ?? array() );

		return new \WP_REST_Response(
			array(
				'final_result'            => $final_result,
				'form_type'               => $form_type,
				'page_id'                 => $page_id,
				'form_title'              => $submission_result['form_title'] ?? null,
				'submission_status'       => $submission_result['status'] ?? 'unknown',
				'submission_result'       => $submission_result,
				'delivery_status'         => $delivery_status,
				'mail_log_source'         => $mail_log_source,
				'fallback_used'           => $fallback_used,
				'submitted_fields_masked' => $submission_result['submitted_fields_masked']
				                            ?? $submission_result['recipient_masked'] ?? null,
				'warnings'                => $all_warnings,
				'note'                    => 'Full report returned inline. No persistent report storage is used.',
			),
			200
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Queries the mail log for an entry written within $window_seconds of now.
	 * Returns delivery_status and the source plugin name.
	 *
	 * Because the form submission is a synchronous loopback call that completes
	 * before run_test() returns, the mail log entry should already be present by
	 * the time this method is called.
	 *
	 * @return array{delivery_status:string,source_plugin:string|null}
	 */
	private function check_mail_delivery( int $window_seconds ): array {
		$logs_result = $this->mail_log->get_recent_logs( 10 );

		if ( ! ( $logs_result['supported'] ?? false ) ) {
			return array( 'delivery_status' => 'unverified', 'source_plugin' => null );
		}

		$logs        = $logs_result['logs'] ?? array();
		$source      = $logs_result['source_plugin'] ?? null;
		$cutoff_time = time() - $window_seconds;

		foreach ( $logs as $log ) {
			$ts = $log['timestamp'] ?? null;
			if ( null === $ts ) {
				continue;
			}

			// strtotime is safe here — both the DB write and this comparison
			// happen on the same server with the same timezone.
			$log_time = strtotime( (string) $ts );
			if ( false === $log_time || $log_time < $cutoff_time ) {
				continue;
			}

			$log_status = strtolower( (string) ( $log['status'] ?? '' ) );

			if ( in_array( $log_status, array( 'sent', 'success', 'logged', '' ), true ) ) {
				return array( 'delivery_status' => 'sent_confirmed_by_log', 'source_plugin' => $source );
			}

			if ( in_array( $log_status, array( 'failed', 'error', 'bounced' ), true ) ) {
				return array( 'delivery_status' => 'log_shows_failed', 'source_plugin' => $source );
			}
		}

		// Log plugin available but no matching recent entry (e.g. slightly outside window).
		return array( 'delivery_status' => 'unverified', 'source_plugin' => $source );
	}

	/**
	 * Maps submission outcome + delivery check into a single final_result value.
	 *
	 *   pass        — Divi accepted the submission AND mail log confirmed delivery
	 *   partial     — Divi accepted but delivery unverified, OR fallback mail sent
	 *   fail        — Divi rejected, OR mail log shows failed delivery
	 *   unsupported — Form test not supported and no fallback was run
	 */
	private function compute_final_result( array $submission_result, string $delivery_status, bool $fallback_used ): string {
		$sub_status = $submission_result['status'] ?? 'unknown';

		if ( $fallback_used ) {
			return 'sent' === $sub_status ? 'partial' : 'fail';
		}

		if ( 'submitted' === $sub_status ) {
			if ( 'sent_confirmed_by_log' === $delivery_status ) {
				return 'pass';
			}
			if ( 'log_shows_failed' === $delivery_status ) {
				return 'fail';
			}
			// Submitted but delivery unverified.
			return 'partial';
		}

		if ( in_array( $sub_status, array( 'failed', 'fail' ), true ) ) {
			return 'fail';
		}

		if ( 'unsupported' === $sub_status ) {
			return 'unsupported';
		}

		return 'fail';
	}
}
