<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class StringArray {

	/**
	 * Sanitize a mixed array payload down to unique, non-empty strings.
	 *
	 * @param mixed $value Potentially mixed array input.
	 * @return string[]
	 */
	public static function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $entry ): string => sanitize_text_field( (string) $entry ),
						$value
					),
					static fn( string $entry ): bool => $entry !== ''
				)
			)
		);
	}
}
