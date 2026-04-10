<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

trait FormatsDocsGuidance {

	/**
	 * @param array<string, mixed> $guidance
	 */
	protected static function format_guidance_line( array $guidance ): string {
		$prefix = sanitize_text_field( (string) ( $guidance['title'] ?? '' ) );

		if ( $prefix === '' ) {
			$prefix = sanitize_text_field( (string) ( $guidance['sourceKey'] ?? '' ) );
		}

		$excerpt = sanitize_textarea_field( (string) ( $guidance['excerpt'] ?? '' ) );

		if ( $excerpt === '' ) {
			return '';
		}

		return $prefix !== '' ? "{$prefix}: {$excerpt}" : $excerpt;
	}
}
