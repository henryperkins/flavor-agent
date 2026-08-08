<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

use FlavorAgent\Activity\ActivityStorageContext;

final class Signer {

	/**
	 * @return array{statement: string, signature: string, keyId: string}|null
	 */
	public static function sign(
		string $canonical_statement,
		?ActivityStorageContext $storage_context = null,
		?string $private_key = null
	): ?array {
		$sk = $private_key ?? KeyManager::private_key();

		if ( null === $sk ) {
			return null;
		}

		try {
			$public_key = KeyManager::public_key( $sk );
			$key_id     = null !== $public_key ? KeyManager::key_id( $public_key ) : null;

			if ( null === $public_key || null === $key_id ) {
				return null;
			}

			if ( ! KeyManager::ensure_registered( $storage_context, $public_key, $key_id ) ) {
				return null;
			}

			$signature = sodium_crypto_sign_detached( $canonical_statement, $sk );

			return [
				'statement' => $canonical_statement,
				'signature' => $signature,
				'keyId'     => $key_id,
			];
		} finally {
			sodium_memzero( $sk );
		}
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
