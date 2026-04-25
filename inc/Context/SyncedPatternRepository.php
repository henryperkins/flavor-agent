<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class SyncedPatternRepository {
	private const QUERY_BATCH_SIZE = 100;

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_patterns(
		?string $sync_status = 'synced',
		bool $include_content = false,
		?int $limit = null,
		int $offset = 0,
		?string $search = null,
		bool $enforce_access = true
	): array {
		if ( 0 === $limit ) {
			return [];
		}

		$requested_status = $this->normalize_requested_sync_status( $sync_status );
		$search_term      = $this->normalize_search_term( $search );
		$batch_size       = $this->resolve_batch_size( $limit, $offset );
		$query_offset     = 0;
		$remaining_offset = max( 0, $offset );
		$patterns         = [];

		while ( true ) {
			$posts = $this->query_posts( $batch_size, $query_offset );

			if ( [] === $posts ) {
				break;
			}

			$query_offset += count( $posts );

			foreach ( $posts as $post ) {
				if ( ! is_object( $post ) ) {
					continue;
				}

				if ( $enforce_access && ! $this->can_read_pattern( $post ) ) {
					continue;
				}

				$pattern = $this->normalize_pattern( $post, $include_content );

				if ( [] === $pattern || ! $this->matches_requested_sync_status( $pattern, $requested_status ) ) {
					continue;
				}

				if ( '' !== $search_term && ! $this->matches_search_filter( $pattern, $search_term ) ) {
					continue;
				}

				if ( $remaining_offset > 0 ) {
					--$remaining_offset;
					continue;
				}

				$patterns[] = $pattern;

				if ( null !== $limit && count( $patterns ) >= $limit ) {
					return $patterns;
				}
			}

			if ( count( $posts ) < $batch_size ) {
				break;
			}
		}

		return $patterns;
	}

	public function count_patterns( ?string $sync_status = 'synced', ?string $search = null, bool $enforce_access = true ): int {
		$requested_status = $this->normalize_requested_sync_status( $sync_status );
		$search_term      = $this->normalize_search_term( $search );
		$batch_size       = self::QUERY_BATCH_SIZE;
		$query_offset     = 0;
		$total            = 0;

		while ( true ) {
			$posts = $this->query_posts( $batch_size, $query_offset );

			if ( [] === $posts ) {
				break;
			}

			$query_offset += count( $posts );

			foreach ( $posts as $post ) {
				if ( ! is_object( $post ) ) {
					continue;
				}

				if ( $enforce_access && ! $this->can_read_pattern( $post ) ) {
					continue;
				}

				$pattern = $this->normalize_pattern( $post, false );

				if ( [] === $pattern || ! $this->matches_requested_sync_status( $pattern, $requested_status ) ) {
					continue;
				}

				if ( '' !== $search_term && ! $this->matches_search_filter( $pattern, $search_term ) ) {
					continue;
				}

				++$total;
			}

			if ( count( $posts ) < $batch_size ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_pattern( int $pattern_id, bool $enforce_access = true ): ?array {
		if ( $pattern_id <= 0 || ! function_exists( 'get_post' ) ) {
			return null;
		}

		$post = get_post( $pattern_id );

		if ( ! is_object( $post ) ) {
			return null;
		}

		if ( $enforce_access && ! $this->can_read_pattern( $post ) ) {
			return null;
		}

		$pattern = $this->normalize_pattern( $post, true );

		return [] === $pattern ? null : $pattern;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_indexable_patterns(): array {
		return $this->for_patterns( 'all', true, null, 0, null, false );
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

	private function can_read_pattern( object $post ): bool {
		$post_id = absint( $post->ID ?? 0 );

		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'current_user_can' ) && current_user_can( 'read_post', $post_id ) ) {
			return true;
		}

		return 'publish' === sanitize_key( (string) ( $post->post_status ?? '' ) );
	}

	/**
	 * @return array<int, object>
	 */
	private function query_posts( int $limit, int $offset ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return [];
		}

		$args = [
			'post_type'      => 'wp_block',
			'post_status'    => 'any',
			'posts_per_page' => max( 1, $limit ),
			'offset'         => max( 0, $offset ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		return get_posts( $args );
	}

	private function normalize_search_term( ?string $search ): string {
		return is_string( $search ) ? strtolower( sanitize_text_field( $search ) ) : '';
	}

	private function resolve_batch_size( ?int $limit, int $offset ): int {
		$requested_window = max( 0, $offset );

		if ( null !== $limit ) {
			$requested_window += max( 0, $limit );
		}

		return max( self::QUERY_BATCH_SIZE, $requested_window, 1 );
	}

	private function matches_requested_sync_status( array $pattern, string $requested_status ): bool {
		return 'all' === $requested_status || ( $pattern['syncStatus'] ?? '' ) === $requested_status;
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
