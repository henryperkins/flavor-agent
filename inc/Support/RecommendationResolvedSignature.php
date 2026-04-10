<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RecommendationResolvedSignature {

	public static function from_payload( string $surface, array $payload ): string {
		$normalized_payload = [
			'surface' => sanitize_key( $surface ),
			'payload' => self::normalize_value( $payload ),
		];

		$payload = wp_json_encode(
			$normalized_payload
		);

		if ( ! is_string( $payload ) || '' === $payload ) {
			$payload = serialize( $normalized_payload );
		}

		return hash( 'sha256', $payload );
	}

	private static function normalize_value( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			if ( self::is_list_array( $value ) ) {
				return array_map( [ self::class, 'normalize_value' ], $value );
			}

			ksort( $value );

			$normalized = [];
			foreach ( $value as $key => $entry ) {
				$normalized[ (string) $key ] = self::normalize_value( $entry );
			}

			return $normalized;
		}

		if (
			is_string( $value )
			|| is_int( $value )
			|| is_float( $value )
			|| is_bool( $value )
			|| null === $value
		) {
			return $value;
		}

		return null;
	}

	private static function is_list_array( array $value ): bool {
		if ( [] === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
