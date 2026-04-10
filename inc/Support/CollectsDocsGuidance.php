<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

use FlavorAgent\Cloudflare\AISearchClient;

final class CollectsDocsGuidance {

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
		$query          = $build_query( $context, $prompt );
		$entity_key     = $build_entity_key( $context, $query );
		$family_context = $build_family_context( $context, $prompt, $entity_key );

		return AISearchClient::maybe_search_with_cache_fallbacks( $query, $entity_key, $family_context );
	}
}
