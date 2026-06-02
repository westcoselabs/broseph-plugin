<?php
declare( strict_types=1 );

namespace Broseph\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailTestService {

	public function send_test( array $params ): array {
		$to_raw            = isset( $params['to'] ) ? sanitize_email( (string) $params['to'] ) : '';
		$label             = sanitize_text_field( (string) ( $params['label'] ?? 'Broseph mail test' ) );
		$include_site_info = (bool) ( $params['include_site_info'] ?? true );

		// Fall back to admin email if none given or invalid.
		if ( empty( $to_raw ) || ! is_email( $to_raw ) ) {
			$to_raw = (string) get_option( 'admin_email', '' );
		}

		if ( ! is_email( $to_raw ) ) {
			return array(
				'status'            => 'failed',
				'wp_mail_result'    => false,
				'recipient_masked'  => '***',
				'recipient_domain'  => null,
				'subject'           => null,
				'timestamp'         => current_time( 'c', true ),
				'notes'             => array( 'No valid recipient address could be determined.' ),
			);
		}

		$subject   = sprintf( '[Broseph Test] Mail delivery check for %s', site_url() );
		$timestamp = current_time( 'c', true );
		$body      = $this->build_body( $label, $timestamp, $include_site_info );
		$headers   = array( 'Content-Type: text/plain; charset=UTF-8' );

		$result = wp_mail( $to_raw, $subject, $body, $headers );

		$notes = array();
		if ( ! $result ) {
			$notes[] = 'wp_mail() returned false. Verify WP Mail SMTP or server mail configuration.';
		}

		return array(
			'status'           => $result ? 'sent' : 'failed',
			'wp_mail_result'   => $result,
			'recipient_masked' => $this->mask_email( $to_raw ),
			'recipient_domain' => explode( '@', $to_raw )[1] ?? null,
			'subject'          => $subject,
			'timestamp'        => $timestamp,
			'notes'            => $notes,
		);
	}

	private function build_body( string $label, string $timestamp, bool $include_site_info ): string {
		$lines = array(
			'Broseph Mail Delivery Test',
			'==========================',
			'',
			'Label:     ' . $label,
			'Timestamp: ' . $timestamp,
			'Triggered: Open Claw / Broseph Plugin v' . BROSEPH_VERSION,
			'',
		);

		if ( $include_site_info ) {
			$lines[] = 'Site URL:   ' . site_url();
			$lines[] = 'WordPress:  ' . get_bloginfo( 'version' );
			$lines[] = 'PHP:        ' . PHP_VERSION;
			$lines[] = '';
		}

		$lines[] = 'This is an automated delivery test. No action required.';

		return implode( "\n", $lines );
	}

	/**
	 * Returns "b***@domain.com" — first local char + *** + @domain.
	 * Never logs or stores the original address.
	 */
	private function mask_email( string $email ): string {
		$parts = explode( '@', $email, 2 );
		if ( count( $parts ) !== 2 ) {
			return '***';
		}
		$local  = $parts[0];
		$domain = $parts[1];

		return ( $local[0] ?? '*' ) . '***@' . $domain;
	}
}
