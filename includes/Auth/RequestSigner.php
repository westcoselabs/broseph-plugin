<?php
declare( strict_types=1 );

namespace Broseph\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RequestSigner {

	private const TIMESTAMP_WINDOW = 300; // 5 minutes
	private const NONCE_TTL        = 600; // 10 minutes

	public function verify( \WP_REST_Request $request ): bool|\WP_Error {
		$site_id   = (string) $request->get_header( 'X-Broseph-Site-Id' );
		$timestamp = (string) $request->get_header( 'X-Broseph-Timestamp' );
		$nonce     = (string) $request->get_header( 'X-Broseph-Nonce' );
		$signature = (string) $request->get_header( 'X-Broseph-Signature' );

		if ( ! $site_id || ! $timestamp || ! $nonce || ! $signature ) {
			return new \WP_Error(
				'broseph_missing_headers',
				'Missing required authentication headers.',
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( (string) get_option( 'broseph_site_id', '' ), $site_id ) ) {
			return new \WP_Error(
				'broseph_invalid_site_id',
				'Site ID does not match.',
				array( 'status' => 401 )
			);
		}

		$ts = (int) $timestamp;
		if ( $ts <= 0 || abs( time() - $ts ) > self::TIMESTAMP_WINDOW ) {
			return new \WP_Error(
				'broseph_expired_timestamp',
				'Request timestamp is outside the allowed window.',
				array( 'status' => 401 )
			);
		}

		$nonce_key = 'broseph_nonce_' . hash( 'sha256', $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			return new \WP_Error(
				'broseph_nonce_replayed',
				'Nonce has already been used.',
				array( 'status' => 401 )
			);
		}

		$expected = $this->compute_signature( $request );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error(
				'broseph_invalid_signature',
				'Request signature is invalid.',
				array( 'status' => 401 )
			);
		}

		set_transient( $nonce_key, '1', self::NONCE_TTL );

		return true;
	}

	public function compute_signature( \WP_REST_Request $request ): string {
		$method    = strtoupper( $request->get_method() );
		$path      = $request->get_route();
		$timestamp = (string) $request->get_header( 'X-Broseph-Timestamp' );
		$nonce     = (string) $request->get_header( 'X-Broseph-Nonce' );
		$body_hash = hash( 'sha256', (string) $request->get_body() );

		$payload = implode( "\n", array( $method, $path, $timestamp, $nonce, $body_hash ) );

		return hash_hmac( 'sha256', $payload, (string) get_option( 'broseph_shared_secret', '' ) );
	}
}
