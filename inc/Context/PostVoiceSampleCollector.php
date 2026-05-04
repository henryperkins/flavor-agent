<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class PostVoiceSampleCollector {

	public const SUPPORTED_POST_TYPES = [ 'post', 'page' ];

	private const OPENING_MAX_CHARS = 1500;

	public function __construct(
		private PostContentRenderer $post_content_renderer
	) {
	}

	/**
	 * @return array<int, array{title: string, published: string, opening: string}>
	 */
	public function for_post( int $post_id, string $post_type ): array {
		if ( ! in_array( $post_type, self::SUPPORTED_POST_TYPES, true ) ) {
			return [];
		}

		$author_id = $this->resolve_author_id( $post_id );
		if ( $author_id <= 0 ) {
			return [];
		}

		$candidates = get_posts(
			[
				'post_type'              => $post_type,
				'author'                 => $author_id,
				'post_status'            => 'publish',
				'posts_per_page'         => 3,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Excluding current post avoids duplicate sample content while returning recent authored posts.
				'post__not_in'           => $post_id > 0 ? [ $post_id ] : [],
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'has_password'           => false,
				'suppress_filters'       => false,
			]
		);

		$samples = [];
		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof \WP_Post ) {
				continue;
			}

			if ( '' !== (string) ( $candidate->post_password ?? '' ) ) {
				continue;
			}

			if ( ! current_user_can( 'read_post', (int) $candidate->ID ) ) {
				continue;
			}

			try {
				$rendered = $this->post_content_renderer->extract(
					(string) ( $candidate->post_content ?? '' ),
					[ 'postId' => (int) $candidate->ID ]
				);
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surface sample-render failures without aborting recommendations.
				error_log(
					sprintf(
						'[flavor-agent] PostVoiceSampleCollector: render failed for post %d - %s',
						(int) $candidate->ID,
						$e->getMessage()
					)
				);
				continue;
			}

			if ( str_contains( $rendered, '[block render failed:' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surface per-block sample render failures without aborting recommendations.
				error_log(
					sprintf(
						'[flavor-agent] PostVoiceSampleCollector: dropping post %d due to block render failure marker',
						(int) $candidate->ID
					)
				);
				continue;
			}

			$opening = self::strip_attribute_references( $rendered );
			$opening = self::truncate_opening( $opening );

			if ( '' === $opening ) {
				continue;
			}

			$samples[] = [
				'title'     => sanitize_text_field( (string) ( $candidate->post_title ?? '' ) ),
				'published' => self::format_published( $candidate ),
				'opening'   => $opening,
			];
		}

		return $samples;
	}

	private function resolve_author_id( int $post_id ): int {
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			return $post instanceof \WP_Post ? (int) $post->post_author : 0;
		}

		return (int) get_current_user_id();
	}

	private static function strip_attribute_references( string $rendered ): string {
		$position = strpos( $rendered, '[Attribute references]' );

		if ( false === $position ) {
			return $rendered;
		}

		return rtrim( substr( $rendered, 0, $position ) );
	}

	private static function truncate_opening( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		if ( self::utf8_length( $text ) <= self::OPENING_MAX_CHARS ) {
			return $text;
		}

		$window     = self::utf8_substr( $text, 0, self::OPENING_MAX_CHARS );
		$last_break = strrpos( $window, "\n\n" );

		if ( false !== $last_break && $last_break > 0 ) {
			return rtrim( substr( $window, 0, $last_break ) );
		}

		return $window . '…';
	}

	private static function utf8_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		if ( preg_match_all( '/./us', $text, $matches ) ) {
			return count( $matches[0] );
		}

		return strlen( $text );
	}

	private static function utf8_substr( string $text, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $start, $length, 'UTF-8' );
		}

		if ( preg_match_all( '/./us', $text, $matches ) ) {
			return implode( '', array_slice( $matches[0], $start, $length ) );
		}

		return substr( $text, $start, $length );
	}

	private static function format_published( \WP_Post $candidate ): string {
		$source = (string) ( $candidate->post_date_gmt ?? '' );
		if ( '' === $source ) {
			$source = (string) ( $candidate->post_date ?? '' );
		}

		if ( '' === $source ) {
			return '';
		}

		return (string) mysql2date( 'Y-m-d', $source );
	}
}
