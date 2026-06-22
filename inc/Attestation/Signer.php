<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

final class Signer {

	/**
	 * @return array{statement: string, signature: string, keyId: string}|null
	 */
	public static function sign( string $canonical_statement ): ?array {
		$sk = KeyManager::private_key();

		if ( null === $sk ) {
			return null;
		}

		KeyManager::ensure_registered();

		return [
			'statement'  => $canonical_statement,
			'signature' => sodium_crypto_sign_detached( $canonical_statement, $sk ),
			'keyId'     => (string) KeyManager::key_id(),
		];
	}

	public static function verify( string $canonical_statement, string $signature, string $public_key ): bool {
		if (
			SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature )
			|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key )
		) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $signature, $canonical_statement, $public_key );
	}

	private function __construct() {}
}
