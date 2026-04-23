<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class SyncedPatternRepository {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_patterns(
		?string $sync_status = 'synced',
		bool $include_content = false,
		?int $limit = null,
		int $offset = 0,
		?string $search = null
	): array {
		$patterns = $this->collect_patterns( $sync_status, $include_content, $search );

		if ( $offset > 0 || null !== $limit ) {
			$patterns = array_slice(
				$patterns,
				max( 0, $offset ),
				null !== $limit ? max( 0, $limit ) : null
			);
		}

		return $patterns;
	}

	public function count_patterns( ?string $sync_status = 'synced', ?string $search = null ): int {
		return count( $this->collect_patterns( $sync_status, false, $search ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_pattern( int $pattern_id ): ?array {
		if ( $pattern_id <= 0 || ! function_exists( 'get_post' ) ) {
			return null;
		}

		$post = get_post( $pattern_id );

		if ( ! is_object( $post ) ) {
			return null;
		}

		$pattern = $this->normalize_pattern( $post, true );

		return [] === $pattern ? null : $pattern;
	}

	private function normalize_requested_sync_status( ?string $sync_status ): string {
		$requested_status = is_string( $sync_status )
			? sanitize_key( $sync_status )
			: 'synced';

		if ( in_array( $requested_status, [ 'synced', 'partial', 'unsynced', 'all' ], true ) ) {
			return $requested_status;
		}

		return 'synced';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_patterns( ?string $sync_status, bool $include_content, ?string $search ): array {
		$requested_status = $this->normalize_requested_sync_status( $sync_status );
		$posts            = function_exists( 'get_posts' )
			? get_posts(
				[
					'post_type'      => 'wp_block',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				]
			)
			: [];
		$patterns         = [];
		$search_term      = is_string( $search ) ? strtolower( sanitize_text_field( $search ) ) : '';

		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) ) {
				continue;
			}

			$pattern = $this->normalize_pattern( $post, $include_content );

			if ( [] === $pattern ) {
				continue;
			}

			if ( 'all' !== $requested_status && ( $pattern['syncStatus'] ?? '' ) !== $requested_status ) {
				continue;
			}

			if ( '' !== $search_term && ! $this->matches_search_filter( $pattern, $search_term ) ) {
				continue;
			}

			$patterns[] = $pattern;
		}

		return $patterns;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalize_pattern( object $post, bool $include_content = true ): array {
		$post_id   = absint( $post->ID ?? 0 );
		$post_type = sanitize_key( (string) ( $post->post_type ?? '' ) );

		if ( $post_id <= 0 || 'wp_block' !== $post_type ) {
			return [];
		}

		$wp_pattern_sync_status = '';

		if ( function_exists( 'get_post_meta' ) ) {
			$wp_pattern_sync_status = sanitize_key(
				(string) get_post_meta( $post_id, 'wp_pattern_sync_status', true )
			);
		}

		if ( ! in_array( $wp_pattern_sync_status, [ 'partial', 'unsynced' ], true ) ) {
			$wp_pattern_sync_status = '';
		}

		$pattern = [
			'id'                  => $post_id,
			'title'               => sanitize_text_field( (string) ( $post->post_title ?? '' ) ),
			'slug'                => (string) ( $post->post_name ?? '' ),
			'status'              => sanitize_key( (string) ( $post->post_status ?? '' ) ),
			'authorId'            => absint( $post->post_author ?? 0 ),
			'dateGmt'             => sanitize_text_field( (string) ( $post->post_date_gmt ?? '' ) ),
			'modifiedGmt'         => sanitize_text_field( (string) ( $post->post_modified_gmt ?? '' ) ),
			'syncStatus'          => $this->resolve_sync_status( $wp_pattern_sync_status ),
			'wpPatternSyncStatus' => $wp_pattern_sync_status,
		];

		if ( $include_content ) {
			$pattern['content'] = (string) ( $post->post_content ?? '' );
		}

		return $pattern;
	}

	private function resolve_sync_status( string $wp_pattern_sync_status ): string {
		if ( 'partial' === $wp_pattern_sync_status ) {
			return 'partial';
		}

		if ( 'unsynced' === $wp_pattern_sync_status ) {
			return 'unsynced';
		}

		return 'synced';
	}

	private function matches_search_filter( array $pattern, string $search_term ): bool {
		$haystacks = [
			strtolower( (string) ( $pattern['title'] ?? '' ) ),
			strtolower( (string) ( $pattern['slug'] ?? '' ) ),
		];

		foreach ( $haystacks as $haystack ) {
			if ( str_contains( $haystack, $search_term ) ) {
				return true;
			}
		}

		return false;
	}
}
