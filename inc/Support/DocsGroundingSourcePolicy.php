<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

/**
 * Non-gating source labels for docs-grounding chunks.
 *
 * Trust and currency of the corpus are owned by scripts/update-docs-ai-search.js
 * at ingestion time; the runtime only labels chunks for display and prompts.
 */
final class DocsGroundingSourcePolicy {

	public const SOURCE_DEVELOPER_DOCS = 'developer-docs';
	public const SOURCE_DEVELOPER_BLOG = 'developer-blog';
	public const SOURCE_MAKE_CORE      = 'make-core';
	public const SOURCE_MAKE_AI        = 'make-ai';
	public const SOURCE_WORDPRESS_NEWS = 'wordpress-news';

	public static function label_for_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) ) {
			return self::SOURCE_DEVELOPER_DOCS;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = (string) ( $parts['path'] ?? '' );

		if ( 'make.wordpress.org' === $host ) {
			if ( '/ai' === $path || str_starts_with( $path, '/ai/' ) ) {
				return self::SOURCE_MAKE_AI;
			}

			return self::SOURCE_MAKE_CORE;
		}

		if ( 'wordpress.org' === $host && ( '/news' === $path || str_starts_with( $path, '/news/' ) ) ) {
			return self::SOURCE_WORDPRESS_NEWS;
		}

		if ( 'developer.wordpress.org' === $host && str_starts_with( $path, '/news/' ) ) {
			return self::SOURCE_DEVELOPER_BLOG;
		}

		return self::SOURCE_DEVELOPER_DOCS;
	}
}
