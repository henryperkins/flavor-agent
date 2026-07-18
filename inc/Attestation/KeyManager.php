<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

final class KeyManager {

	private const REGISTRY_OPTION = 'flavor_agent_attestation_public_keys';

	public static function private_key(): ?string {
		$configured = \defined( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' )
			? (string) \constant( 'FLAVOR_AGENT_ATTEST_PRIVATE_KEY' )
			: '';
		$raw        = (string) \apply_filters( 'flavor_agent_attest_private_key', $configured );

		if ( '' === $raw ) {
			return null;
		}

		$decoded = base64_decode( $raw, true );

		return false !== $decoded && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $decoded )
			? $decoded
			: null;
	}

	public static function configured(): bool {
		return null !== self::private_key();
	}

	public static function public_key(): ?string {
		$sk = self::private_key();

		return null === $sk ? null : sodium_crypto_sign_publickey_from_secretkey( $sk );
	}

	public static function key_id(): ?string {
		$pk = self::public_key();

		return null === $pk ? null : substr( hash( 'sha256', $pk ), 0, 32 );
	}

	public static function ensure_registered(): void {
		$pk  = self::public_key();
		$kid = self::key_id();

		if ( null === $pk || null === $kid ) {
			return;
		}

		$registry = \get_option( self::REGISTRY_OPTION, [] );
		$registry = is_array( $registry ) ? $registry : [];

		$changed = false;
		foreach ( $registry as $id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			if ( $id === $kid ) {
				continue;
			}

			if ( 'active' === ( $record['status'] ?? '' ) ) {
				$registry[ $id ]['status'] = 'retired';
				$changed                   = true;
			}
		}

		$existing   = is_array( $registry[ $kid ] ?? null ) ? $registry[ $kid ] : [];
		$registered = [
			'kid'       => $kid,
			'x'         => self::b64url( $pk ),
			'status'    => 'active',
			'createdAt' => '' !== (string) ( $existing['createdAt'] ?? '' )
				? (string) $existing['createdAt']
				: gmdate( 'c' ),
		];

		if ( $existing !== $registered ) {
			$registry[ $kid ] = $registered;
			$changed          = true;
		}

		if ( $changed ) {
			\update_option( self::REGISTRY_OPTION, $registry, false );
		}
	}

	/**
	 * @return array{keys: list<array{kty: string, crv: string, x: string, kid: string, use: string, alg: string, status: string, createdAt: string}>}
	 */
	public static function jwks(): array {
		$registry = \get_option( self::REGISTRY_OPTION, [] );
		$keys     = [];

		foreach ( is_array( $registry ) ? $registry : [] as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$keys[] = [
				'kty'       => 'OKP',
				'crv'       => 'Ed25519',
				'x'         => (string) $record['x'],
				'kid'       => (string) $record['kid'],
				'use'       => 'sig',
				'alg'       => 'EdDSA',
				'status'    => (string) ( $record['status'] ?? '' ),
				'createdAt' => (string) ( $record['createdAt'] ?? '' ),
			];
		}

		return [ 'keys' => $keys ];
	}

	public static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private function __construct() {}
}
