<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class NonNegativeInteger {

	public static function normalize( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( is_numeric( $value ) ) {
			$normalized = (int) $value;
			return $normalized >= 0 ? $normalized : null;
		}

		return null;
	}
}
