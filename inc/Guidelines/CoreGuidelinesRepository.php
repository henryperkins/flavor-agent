<?php

declare(strict_types=1);

namespace FlavorAgent\Guidelines;

final class CoreGuidelinesRepository implements GuidelinesRepository {

	public const POST_TYPE        = 'wp_guideline';
	public const LEGACY_POST_TYPE = 'wp_content_guideline';
	public const TAXONOMY         = 'wp_guideline_type';
	private const BLOCK_PREFIX    = '_guideline_block_';

	public function __construct(
		private readonly string $post_type = self::POST_TYPE
	) {
	}

	public function source(): string {
		return self::POST_TYPE === $this->post_type ? 'core' : 'gutenberg_experiment';
	}

	public static function available_post_type(): ?string {
		if ( ! function_exists( 'post_type_exists' ) ) {
			return null;
		}

		foreach ( [ self::POST_TYPE, self::LEGACY_POST_TYPE ] as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				return $post_type;
			}
		}

		return null;
	}

	public static function is_available(): bool {
		return null !== self::available_post_type();
	}

	/**
	 * @return array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>}
	 */
	public function get_all(): array {
		$post = $this->get_guidelines_post();

		if ( ! is_object( $post ) ) {
			return $this->empty_guidelines();
		}

		$post_id = (int) ( $post->ID ?? 0 );

		if ( $post_id <= 0 ) {
			return $this->empty_guidelines();
		}

		return [
			'site'       => $this->get_standard_guideline( $post_id, 'site' ),
			'copy'       => $this->get_standard_guideline( $post_id, 'copy' ),
			'images'     => $this->get_standard_guideline( $post_id, 'images' ),
			'additional' => $this->get_standard_guideline( $post_id, 'additional' ),
			'blocks'     => $this->get_block_guidelines( $post_id ),
		];
	}

	private function get_guidelines_post(): ?object {
		if ( ! function_exists( 'get_posts' ) ) {
			return null;
		}

		$args = [
			'post_type'      => $this->post_type,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		if (
			self::POST_TYPE === $this->post_type
			&& function_exists( 'taxonomy_exists' )
			&& taxonomy_exists( self::TAXONOMY )
		) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Taxonomy is static, narrow, and cached once for guideline discovery.
			$args['tax_query'] = [
				[
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => 'content',
				],
			];
		}

		$posts = get_posts( $args );

		return is_array( $posts ) && isset( $posts[0] ) && is_object( $posts[0] )
			? $posts[0]
			: null;
	}

	private function get_standard_guideline( int $post_id, string $category ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		return \FlavorAgent\Guidelines::sanitize_guideline_text(
			get_post_meta( $post_id, '_guideline_' . $category, true )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_block_guidelines( int $post_id ): array {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return [];
		}

		$all_meta = get_post_meta( $post_id );

		if ( ! is_array( $all_meta ) ) {
			return [];
		}

		$raw_blocks = [];

		foreach ( $all_meta as $meta_key => $meta_values ) {
			if ( ! is_string( $meta_key ) || ! str_starts_with( $meta_key, self::BLOCK_PREFIX ) ) {
				continue;
			}

			$block_name = $this->meta_key_to_block_name( $meta_key );

			if ( '' === $block_name ) {
				continue;
			}

			$value = is_array( $meta_values ) ? ( $meta_values[0] ?? '' ) : $meta_values;

			$raw_blocks[ $block_name ] = [
				'guidelines' => $value,
			];
		}

		return \FlavorAgent\Guidelines::sanitize_block_guidelines( $raw_blocks );
	}

	private function meta_key_to_block_name( string $meta_key ): string {
		$without_prefix = substr( $meta_key, strlen( self::BLOCK_PREFIX ) );
		$block_name     = preg_replace( '/_/', '/', $without_prefix, 1 );

		return is_string( $block_name ) ? $block_name : '';
	}

	/**
	 * @return array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>}
	 */
	private function empty_guidelines(): array {
		return [
			'site'       => '',
			'copy'       => '',
			'images'     => '',
			'additional' => '',
			'blocks'     => [],
		];
	}
}
