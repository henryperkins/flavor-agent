<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

/**
 * Shared sanitization for guidance-chunk excerpt text. Used by both the
 * Cloudflare-AI-Search ingestion path and the Core roadmap guidance path so
 * the excerpt rules (whitespace normalization and truncation) stay aligned.
 */
final class GuidanceExcerpt {

	public const MAX_LENGTH = 360;

	public static function sanitize( string $text, int $max_length = self::MAX_LENGTH ): string {
		$text = sanitize_textarea_field( $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		if ( $max_length > 3 && self::string_length( $text ) > $max_length ) {
			$text = self::string_substr( $text, 0, $max_length - 3 ) . '...';
		}

		return $text;
	}

	private static function string_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		if ( preg_match( '//u', $value ) ) {
			preg_match_all( '/./us', $value, $matches );
			return count( $matches[0] ?? [] );
		}

		return strlen( $value );
	}

	private static function string_substr( string $value, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, $start, $length, 'UTF-8' );
		}

		if ( preg_match( '//u', $value ) ) {
			$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

			if ( ! is_array( $characters ) ) {
				return '';
			}

			return implode( '', array_slice( $characters, $start, $length ) );
		}

		return substr( $value, $start, $length );
	}
}
