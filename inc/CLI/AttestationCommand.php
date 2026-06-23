<?php

declare(strict_types=1);

namespace FlavorAgent\CLI;

use FlavorAgent\Attestation\Verifier;
use FlavorAgent\REST\AttestationController;

final class AttestationCommand {

	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'flavor-agent attestation', self::class );
	}

	/**
	 * Verify a stored Ring III attestation.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attestation ID, for example att_0123abcd.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flavor-agent attestation verify att_0123abcd
	 *
	 * @param array<int, mixed> $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function verify( array $args, array $assoc_args = [] ): void {
		unset( $assoc_args );

		$id = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
		$id = function_exists( 'sanitize_text_field' ) ? \sanitize_text_field( $id ) : $id;

		if ( '' === $id ) {
			\WP_CLI::error( 'Usage: wp flavor-agent attestation verify <attestationId>' );
		}

		$envelope = self::rest_json( '/flavor-agent/v1/attestations/' . rawurlencode( $id ), $id );

		if ( 404 === $envelope['status'] ) {
			\WP_CLI::error( sprintf( 'Attestation not found: %s', $id ) );
		}

		if ( $envelope['status'] < 200 || $envelope['status'] >= 300 ) {
			\WP_CLI::error( 'Attestation public envelope is unavailable.' );
		}

		$keys = self::rest_json( '/flavor-agent/v1/attestations/keys' );

		if ( $keys['status'] < 200 || $keys['status'] >= 300 ) {
			\WP_CLI::error( 'Attestation public key set is unavailable.' );
		}

		$subject       = self::rest_json( '/flavor-agent/v1/attestations/' . rawurlencode( $id ) . '/subject-state', $id );
		$subject_error = null;
		$subject_bytes = null;

		if ( $subject['status'] < 200 || $subject['status'] >= 300 ) {
			$subject_error = (string) ( $subject['data']['error'] ?? 'subject_unavailable' );
		} elseif ( ! is_string( $subject['data']['subject_canonical_b64'] ?? null ) || '' === (string) $subject['data']['subject_canonical_b64'] ) {
			$subject_error = 'subject_payload_invalid';
		} else {
			$subject_bytes = self::b64url_decode( (string) $subject['data']['subject_canonical_b64'] );
		}

		$outcomes = Verifier::evaluate(
			self::b64url_decode( (string) ( $envelope['data']['statement_b64'] ?? '' ) ),
			self::b64url_decode( (string) ( $envelope['data']['signature_b64'] ?? '' ) ),
			$keys['data'],
			$subject_bytes,
			isset( $envelope['data']['reverted_by_attestation_id'] ) ? (string) $envelope['data']['reverted_by_attestation_id'] : null,
			isset( $envelope['data']['superseded_by_attestation_id'] ) ? (string) $envelope['data']['superseded_by_attestation_id'] : null
		);

		if ( null !== $subject_error ) {
			$outcomes[] = 'live_subject_unavailable';
		}

		\WP_CLI::line(
			self::json_encode(
				[
					'attestationId' => $id,
					'outcomes'      => $outcomes,
					'subjectError'  => $subject_error,
				]
			)
		);

		if ( in_array( 'record_tampered', $outcomes, true ) ) {
			\WP_CLI::error( 'Attestation verification failed.' );
		}

		if ( null !== $subject_error ) {
			\WP_CLI::error( 'Attestation verification incomplete.' );
		}

		\WP_CLI::success( 'Attestation verified.' );
	}

	/**
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private static function rest_json( string $route, ?string $id = null ): array {
		if ( ! class_exists( '\WP_REST_Request' ) ) {
			return [
				'status' => 500,
				'data'   => [ 'error' => 'rest_request_unavailable' ],
			];
		}

		$request = new \WP_REST_Request( 'GET', $route );

		if ( null !== $id ) {
			$request->set_param( 'id', $id );
		}

		if ( function_exists( 'rest_do_request' ) ) {
			$response = \rest_do_request( $request );
		} else {
			$response = self::dispatch_attestation_request( $request );
		}

		if ( \is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$status     = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 500 ) : 500;

			return [
				'status' => $status,
				'data'   => [ 'error' => $response->get_error_code() ],
			];
		}

		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 500;
		$data   = method_exists( $response, 'get_data' ) ? $response->get_data() : [];
		$data   = is_array( $data ) ? $data : [];

		return [
			'status' => $status,
			'data'   => $data,
		];
	}

	private static function dispatch_attestation_request( \WP_REST_Request $request ): \WP_REST_Response {
		$controller = new AttestationController();
		$route      = $request->get_route();

		if ( '/flavor-agent/v1/attestations/keys' === $route ) {
			return $controller->get_keys( $request );
		}

		if ( str_ends_with( $route, '/subject-state' ) ) {
			return $controller->get_subject_state( $request );
		}

		return $controller->get_attestation( $request );
	}

	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);

		return false === $decoded ? '' : $decoded;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function json_encode( array $payload ): string {
		$options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
		$json    = function_exists( 'wp_json_encode' )
			? \wp_json_encode( $payload, $options )
			: json_encode( $payload, $options );

		return is_string( $json ) ? $json : '{}';
	}
}
