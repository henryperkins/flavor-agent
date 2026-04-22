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
}
