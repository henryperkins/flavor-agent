<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

use FlavorAgent\Cloudflare\AISearchClient;

final class CollectsDocsGuidance {

	private const MAX_ROADMAP_CHUNKS_BEFORE_DOCS = 1;

	/**
	 * Build one query and run a single cached best-effort search (recommendation mode)
	 * or a cache-only read (signature mode), then merge with roadmap guidance. Grounding
	 * is best-effort: an empty or unreachable backend yields no docs chunks and never
	 * raises an error into the recommendation flow.
	 *
	 * @param callable(array<string, mixed>, string): string $build_query
	 * @param array<string, mixed> $context
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect( callable $build_query, array $context, string $prompt, array $options = [] ): array {
		$query = (string) $build_query( $context, $prompt );

		if ( '' === $query ) {
			$docs_guidance = [];
		} elseif ( 'signature' === (string) ( $options['mode'] ?? 'recommendation' ) ) {
			$docs_guidance = AISearchClient::maybe_search( $query );
		} else {
			$docs_guidance = AISearchClient::maybe_search_best_effort( $query );
		}

		$roadmap_guidance = CoreRoadmapGuidance::collect( $context, [ 'sideEffects' => false ] );

		if ( [] === $roadmap_guidance ) {
			return $docs_guidance;
		}

		if ( [] === $docs_guidance ) {
			return $roadmap_guidance;
		}

		return self::merge_guidance_chunks( $docs_guidance, $roadmap_guidance );
	}

	/**
	 * @param callable(array<string, mixed>, string): string $build_query
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public static function collect_result( callable $build_query, array $context, string $prompt, array $options = [] ): array {
		$guidance = self::collect( $build_query, $context, $prompt, $options );

		return DocsGuidanceResult::from_guidance(
			$guidance,
			(string) ( $options['mode'] ?? 'recommendation' ),
			'best-effort'
		);
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
