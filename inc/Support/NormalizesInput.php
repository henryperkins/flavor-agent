<?php

declare( strict_types = 1 );

namespace FlavorAgent\Support;

trait NormalizesInput {

	/**
	 * Normalize a mixed input to a consistent array structure.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>
	 */
	protected static function normalize_input( mixed $input ): array {
		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		return is_array( $input ) ? $input : [];
	}

	/**
	 * Normalize a mixed input to a recursively normalized associative array.
	 *
	 * @return array<string|int, mixed>
	 */
	protected static function normalize_map( mixed $value ): array {
		$normalized = self::normalize_value( $value );

		return is_array( $normalized ) ? $normalized : [];
	}

	/**
	 * Normalize a mixed input to a recursively normalized list.
	 *
	 * @return array<int, mixed>
	 */
	protected static function normalize_list( mixed $value ): array {
		$normalized = self::normalize_map( $value );

		return array_values( $normalized );
	}

	protected static function normalize_value( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized[ $key ] = self::normalize_value( $entry );
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
}
