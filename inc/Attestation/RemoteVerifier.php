<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

final class RemoteVerifier {

	/**
	 * The callback may return a decoded body directly (legacy) or a structured
	 * response shaped as {status:int,data:array}.
	 *
	 * @param callable(string): array<string, mixed> $get_json
	 * @return array<string, mixed>
	 */
	public static function verify( string $base_url, string $attestation_id, callable $get_json ): array {
		$base_url       = rtrim( trim( $base_url ), '/' );
		$attestation_id = trim( $attestation_id );
		$encoded_id     = rawurlencode( $attestation_id );
		$envelope       = self::response( $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/{$encoded_id}" ) );

		if ( ! self::successful( $envelope ) ) {
			return self::incomplete_result( $attestation_id, 'envelope_unavailable' );
		}

		$keys = self::response( $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/keys" ) );

		if ( ! self::successful( $keys ) ) {
			return self::incomplete_result( $attestation_id, 'keys_unavailable' );
		}

		$subject       = self::response( $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/{$encoded_id}/subject-state" ) );
		$subject_error = null;
		$subject_bytes = null;

		if ( ! self::successful( $subject ) ) {
			$subject_error = (string) ( $subject['data']['error'] ?? 'subject_unavailable' );
		} else {
			$subject_b64 = (string) ( $subject['data']['subject_canonical_b64'] ?? '' );

			if ( '' === $subject_b64 ) {
				$subject_error = 'invalid_subject_state';
			} else {
				$subject_bytes = self::b64url_decode( $subject_b64 );

				if ( null === $subject_bytes ) {
					$subject_error = 'invalid_subject_state';
				}
			}
		}

		$result                 = Verifier::verify(
			$envelope['data'],
			$keys['data'],
			$subject_bytes,
			$attestation_id,
			$base_url,
			static function ( string $id ) use ( $base_url, $get_json ): ?array {
				$response = self::response(
					$get_json( $base_url . '/wp-json/flavor-agent/v1/attestations/' . rawurlencode( $id ) )
				);

				return self::successful( $response )
					? $response['data']
					: [ '_resolution_incomplete' => true ];
			}
		);
		$result['error']        = $subject_error;
		$result['subjectError'] = $subject_error;

		return $result;
	}

	/**
	 * @param array<string, mixed> $value
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private static function response( array $value ): array {
		if ( isset( $value['status'] ) && array_key_exists( 'data', $value ) ) {
			return [
				'status' => (int) $value['status'],
				'data'   => is_array( $value['data'] ) ? $value['data'] : [],
			];
		}

		return [
			'status' => 200,
			'data'   => $value,
		];
	}

	/**
	 * @param array{status: int, data: array<string, mixed>} $response
	 */
	private static function successful( array $response ): bool {
		return $response['status'] >= 200 && $response['status'] < 300;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function incomplete_result( string $attestation_id, string $error ): array {
		return [
			'attestationId'         => $attestation_id,
			'outcomes'              => [],
			'verificationStatus'    => 'incomplete',
			'terminalAttestationId' => null,
			'chainDepth'            => 0,
			'error'                 => $error,
			'subjectError'          => null,
			'exitCode'              => 3,
		];
	}

	private static function b64url_decode( string $value ): ?string {
		$decoded = base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);

		return false === $decoded ? null : $decoded;
	}

	private function __construct() {}
}
