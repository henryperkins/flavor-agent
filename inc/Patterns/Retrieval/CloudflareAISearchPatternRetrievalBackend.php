<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns\Retrieval;

use FlavorAgent\Cloudflare\PatternSearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\Patterns\PatternIndex;

final class CloudflareAISearchPatternRetrievalBackend implements PatternRetrievalBackend {

	/**
	 * @param string[]             $visible_pattern_names
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $context
	 * @return array<int, array{payload: array<string, mixed>, score: float}>|\WP_Error
	 */
	public function search( string $query, array $visible_pattern_names, array $state, array $context ): array|\WP_Error {
		unset( $state );

		$limit = max( 1, (int) ( $context['semanticLimit'] ?? 50 ) );
		$hits  = PatternSearchClient::search_patterns( $query, $visible_pattern_names, $limit );

		if ( is_wp_error( $hits ) ) {
			return $hits;
		}

		$candidates = [];

		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}

			$name    = sanitize_text_field( (string) ( $hit['name'] ?? '' ) );
			$payload = $this->payload_for_hit( $hit, $name );

			if ( [] === $payload ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $payload['name'] ?? $name ) );

			if ( '' === $name ) {
				continue;
			}

			$score = isset( $hit['score'] ) && is_numeric( $hit['score'] ) ? (float) $hit['score'] : 0.0;

			if ( ! isset( $candidates[ $name ] ) || $candidates[ $name ]['score'] < $score ) {
				$candidates[ $name ] = [
					'payload' => $payload,
					'score'   => $score,
				];
			}
		}

		return array_values( $candidates );
	}

	/**
	 * @param array<string, mixed> $hit
	 * @return array<string, mixed>
	 */
	private function payload_for_hit( array $hit, string $name ): array {
		$metadata       = is_array( $hit['metadata'] ?? null ) ? $hit['metadata'] : [];
		$candidate_type = sanitize_key( (string) ( $metadata['candidate_type'] ?? '' ) );
		$source         = sanitize_key( (string) ( $metadata['source'] ?? '' ) );
		$synced_id      = absint( $metadata['synced_id'] ?? 0 );
		$core_block_id  = $this->core_block_id( $name );

		if ( $synced_id > 0 || $core_block_id > 0 || 'user' === $candidate_type || 'synced' === $source ) {
			$id = $synced_id > 0 ? $synced_id : $core_block_id;

			return [
				'id'                  => $id > 0 ? 'core/block/' . $id : $name,
				'name'                => $id > 0 ? 'core/block/' . $id : $name,
				'title'               => '',
				'description'         => '',
				'categories'          => [ 'reusable' ],
				'blockTypes'          => [],
				'templateTypes'       => [],
				'patternOverrides'    => [],
				'type'                => 'user',
				'source'              => 'synced',
				'syncedPatternId'     => $id,
				'syncStatus'          => '',
				'wpPatternSyncStatus' => '',
				'content'             => '',
			];
		}

		$current_pattern = ServerCollector::for_pattern( $name );

		if ( is_array( $current_pattern ) ) {
			$current_pattern['traits'] = PatternIndex::infer_layout_traits( $current_pattern );

			return $current_pattern;
		}

		return [
			'id'               => $name,
			'name'             => $name,
			'title'            => $name,
			'description'      => '',
			'categories'       => [],
			'blockTypes'       => [],
			'templateTypes'    => [],
			'patternOverrides' => [],
			'traits'           => [],
			'type'             => 'registered',
			'source'           => sanitize_text_field( (string) ( $hit['source'] ?? 'cloudflare_ai_search' ) ),
			'content'          => sanitize_textarea_field( (string) ( $hit['text'] ?? '' ) ),
		];
	}

	private function core_block_id( string $name ): int {
		if ( ! preg_match( '#^core/block/(\d+)$#', $name, $matches ) ) {
			return 0;
		}

		return absint( $matches[1] ?? 0 );
	}
}
