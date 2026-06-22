<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Pure evaluation of public attestation verification outcomes. No I/O.
 */
final class Verifier {

	/**
	 * @param array{keys?: array<int, array<string, string>>} $jwks
	 * @return list<string>
	 */
	public static function evaluate(
		string $statement_bytes,
		string $signature_raw,
		array $jwks,
		?string $live_subject_bytes,
		?string $reverted_by_id
	): array {
		$outcomes   = [];
		$statement  = json_decode( $statement_bytes, true );
		$key_id     = is_array( $statement ) ? (string) ( $statement['predicate']['site']['keyId'] ?? '' ) : '';
		$public     = self::public_key_for( $jwks, $key_id );
		$valid      = null !== $public && Signer::verify( $statement_bytes, $signature_raw, $public );
		$outcomes[] = $valid ? 'signature_valid' : 'record_tampered';

		if ( null !== $live_subject_bytes && is_array( $statement ) ) {
			$subject_digest = (string) ( $statement['subject'][0]['digest']['sha256'] ?? '' );

			if ( hash( 'sha256', $live_subject_bytes ) === $subject_digest ) {
				$outcomes[] = 'live_matches_subject';
			} else {
				$outcomes[] = null !== $reverted_by_id && '' !== $reverted_by_id
					? 'reverted_by_attestation'
					: 'live_changed_since_attestation';
			}
		}

		return $outcomes;
	}

	/**
	 * @param array{keys?: array<int, array<string, string>>} $jwks
	 */
	private static function public_key_for( array $jwks, string $key_id ): ?string {
		foreach ( $jwks['keys'] ?? [] as $jwk ) {
			if (
				( $jwk['kid'] ?? '' ) === $key_id
				&& 'OKP' === ( $jwk['kty'] ?? '' )
				&& 'Ed25519' === ( $jwk['crv'] ?? '' )
			) {
				$public = self::b64url_decode( (string) ( $jwk['x'] ?? '' ) );

				return SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $public ) ? $public : null;
			}
		}

		return null;
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
