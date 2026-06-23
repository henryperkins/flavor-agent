<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

final class RemoteVerifier {

	/**
	 * @param callable(string): array<string, mixed> $get_json
	 * @return array<string, mixed>
	 */
	public static function verify( string $base_url, string $attestation_id, callable $get_json ): array {
		$base_url       = rtrim( trim( $base_url ), '/' );
		$attestation_id = trim( $attestation_id );

		$envelope = $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/{$attestation_id}" );
		$jwks     = $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/keys" );
		$subject  = $get_json( "{$base_url}/wp-json/flavor-agent/v1/attestations/{$attestation_id}/subject-state" );

		if ( ! isset( $subject['subject_canonical_b64'] ) || '' === (string) $subject['subject_canonical_b64'] ) {
			return [
				'attestationId' => $attestation_id,
				'outcomes'      => [ 'live_subject_unavailable' ],
				'error'         => 'invalid_subject_state',
				'exitCode'      => 3,
			];
		}

		$outcomes = Verifier::evaluate(
			self::b64url_decode( (string) ( $envelope['statement_b64'] ?? '' ) ),
			self::b64url_decode( (string) ( $envelope['signature_b64'] ?? '' ) ),
			$jwks,
			self::b64url_decode( (string) $subject['subject_canonical_b64'] ),
			isset( $envelope['reverted_by_attestation_id'] ) ? (string) $envelope['reverted_by_attestation_id'] : null,
			isset( $envelope['superseded_by_attestation_id'] ) ? (string) $envelope['superseded_by_attestation_id'] : null
		);

		return [
			'attestationId' => $attestation_id,
			'outcomes'      => $outcomes,
			'error'         => null,
			'exitCode'      => in_array( 'record_tampered', $outcomes, true ) ? 1 : 0,
		];
	}

	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);

		return false === $decoded ? '' : $decoded;
	}

	private function __construct() {}
}
