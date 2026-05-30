<?php
declare( strict_types=1 );

namespace Broseph\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ActionLogger {

	private AuditLogRepository $repository;

	public function __construct( AuditLogRepository $repository ) {
		$this->repository = $repository;
	}

	public function log( string $status, array $context = array() ): void {
		$payload = $context['payload'] ?? null;

		$this->repository->insert(
			array(
				'request_id'      => isset( $context['request_id'] )
					? substr( (string) $context['request_id'], 0, 64 ) : null,
				'actor'           => isset( $context['actor'] )
					? substr( (string) $context['actor'], 0, 100 ) : null,
				'task_type'       => isset( $context['task_type'] )
					? substr( (string) $context['task_type'], 0, 100 ) : null,
				'endpoint'        => isset( $context['endpoint'] )
					? substr( (string) $context['endpoint'], 0, 255 ) : null,
				'method'          => isset( $context['method'] )
					? strtoupper( substr( (string) $context['method'], 0, 10 ) ) : null,
				'payload_hash'    => null !== $payload ? self::hash_payload( $payload ) : null,
				'object_type'     => isset( $context['object_type'] )
					? substr( (string) $context['object_type'], 0, 100 ) : null,
				'object_id'       => isset( $context['object_id'] ) ? (int) $context['object_id'] : null,
				'status'          => substr( $status, 0, 50 ),
				'message'         => $context['message'] ?? null,
				'before_snapshot' => isset( $context['before_snapshot'] )
					? wp_json_encode( $context['before_snapshot'] ) : null,
				'after_snapshot'  => isset( $context['after_snapshot'] )
					? wp_json_encode( $context['after_snapshot'] ) : null,
				'created_at'      => current_time( 'mysql', true ),
			)
		);
	}

	public static function hash_payload( mixed $payload ): string {
		if ( ! is_string( $payload ) ) {
			$payload = (string) wp_json_encode( $payload );
		}
		return hash( 'sha256', $payload );
	}
}
