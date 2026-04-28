<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

use FlavorAgent\Cloudflare\AISearchClient;

final class CollectsDocsGuidance {

	private const MAX_ROADMAP_CHUNKS_BEFORE_DOCS = 1;

	/**
	 * @param callable(array<string, mixed>, string): string $build_query
	 * @param callable(array<string, mixed>, string): string $build_entity_key
	 * @param callable(array<string, mixed>, string, string): array<string, mixed> $build_family_context
	 * @param array<string, mixed> $context
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect(
		callable $build_query,
		callable $build_entity_key,
		callable $build_family_context,
		array $context,
		string $prompt
	): array {
		$query            = $build_query( $context, $prompt );
		$entity_key       = $build_entity_key( $context, $query );
		$family_context   = $build_family_context( $context, $prompt, $entity_key );
		$docs_guidance    = AISearchClient::maybe_search_with_cache_fallbacks( $query, $entity_key, $family_context );
		$roadmap_guidance = CoreRoadmapGuidance::collect( $context );

		if ( [] === $roadmap_guidance ) {
			return $docs_guidance;
		}

		if ( [] === $docs_guidance ) {
			return $roadmap_guidance;
		}

		return self::merge_guidance_chunks( $docs_guidance, $roadmap_guidance );
	}

	/**
	 * @param array<int, array<string, mixed>> $docs_guidance
	 * @param array<int, array<string, mixed>> $roadmap_guidance
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_guidance_chunks( array $docs_guidance, array $roadmap_guidance ): array {
		$merged = [];
		$seen   = [];

		foreach ( self::order_guidance_chunks( $docs_guidance, $roadmap_guidance ) as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$title  = trim( (string) ( $chunk['title'] ?? '' ) );
			$source = sanitize_text_field( (string) ( $chunk['sourceKey'] ?? '' ) );
			$url    = sanitize_url( (string) ( $chunk['url'] ?? '' ) );
			$key    = ( $source !== '' && $url !== '' )
				? "{$source}::{$title}::{$url}"
				: ( $source !== '' ? "{$source}::{$title}" : $url );

			if ( $key === '' || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ]       = true;
			$chunk['title']     = $title;
			$chunk['sourceKey'] = $source;
			if ( isset( $chunk['sourceType'] ) ) {
				$chunk['sourceType'] = sanitize_key( (string) $chunk['sourceType'] );
			}
			$chunk['url'] = $url;
			$merged[]     = $chunk;
		}

		return array_values( $merged );
	}

	/**
	 * Keep the roadmap signal visible without letting it consume the full prompt window.
	 *
	 * @param array<int, array<string, mixed>> $docs_guidance
	 * @param array<int, array<string, mixed>> $roadmap_guidance
	 * @return array<int, array<string, mixed>>
	 */
	private static function order_guidance_chunks( array $docs_guidance, array $roadmap_guidance ): array {
		$leading_roadmap = array_slice( $roadmap_guidance, 0, self::MAX_ROADMAP_CHUNKS_BEFORE_DOCS );
		$remaining       = array_slice( $roadmap_guidance, self::MAX_ROADMAP_CHUNKS_BEFORE_DOCS );

		return array_merge( $leading_roadmap, $docs_guidance, $remaining );
	}
}
