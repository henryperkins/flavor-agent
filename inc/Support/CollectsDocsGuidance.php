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
		$parts = self::collect_parts( $build_query, $context, $prompt, $options );

		return self::merge_parts( $parts['docs'], $parts['roadmap'] );
	}

	/**
	 * The result summary (`available`, `count`, `sourceTypes`, `fingerprint`) is
	 * computed from the AI Search docs chunks only; advisory roadmap chunks ride
	 * along in `guidance` for prompt assembly but can't mask an ungrounded run.
	 *
	 * @param callable(array<string, mixed>, string): string $build_query
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public static function collect_result( callable $build_query, array $context, string $prompt, array $options = [] ): array {
		$parts = self::collect_parts( $build_query, $context, $prompt, $options );

		return DocsGuidanceResult::from_guidance(
			$parts['docs'],
			(string) ( $options['mode'] ?? 'recommendation' ),
			'best-effort',
			self::merge_parts( $parts['docs'], $parts['roadmap'] ),
			$parts['docsDiagnostics']
		);
	}

	/**
	 * @param callable(array<string, mixed>, string): string $build_query
	 * @param array<string, mixed> $context
	 * @return array{
	 *   docs: array<int, array<string, mixed>>,
	 *   docsDiagnostics: array<string, string>,
	 *   roadmap: array<int, array<string, mixed>>
	 * }
	 */
	private static function collect_parts( callable $build_query, array $context, string $prompt, array $options ): array {
		$query = (string) $build_query( $context, $prompt );

		if ( '' === $query ) {
			$docs_guidance    = [];
			$docs_diagnostics = [
				'reason'    => 'query_empty',
				'source'    => 'request',
				'errorCode' => '',
			];
		} elseif ( 'signature' === (string) ( $options['mode'] ?? 'recommendation' ) ) {
			$docs_guidance    = AISearchClient::maybe_search( $query );
			$docs_diagnostics = [
				'reason'    => [] === $docs_guidance ? 'signature_cache_miss' : 'grounded',
				'source'    => 'cache',
				'errorCode' => '',
			];
		} else {
			$docs_result      = AISearchClient::maybe_search_best_effort_result( $query );
			$docs_guidance    = is_array( $docs_result['guidance'] ?? null ) ? $docs_result['guidance'] : [];
			$docs_diagnostics = is_array( $docs_result['diagnostics'] ?? null ) ? $docs_result['diagnostics'] : [];
		}

		return [
			'docs'            => $docs_guidance,
			'docsDiagnostics' => $docs_diagnostics,
			'roadmap'         => CoreRoadmapGuidance::collect( $context, [ 'sideEffects' => false ] ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $docs_guidance
	 * @param array<int, array<string, mixed>> $roadmap_guidance
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_parts( array $docs_guidance, array $roadmap_guidance ): array {
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
