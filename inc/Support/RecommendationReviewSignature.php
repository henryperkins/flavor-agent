<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RecommendationReviewSignature {

	public static function from_payload( string $surface, array $payload ): string {
		return RecommendationSignature::from_payload( $surface, $payload );
	}
}
