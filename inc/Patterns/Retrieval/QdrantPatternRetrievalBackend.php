<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns\Retrieval;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\Patterns\PatternIndex;

final class QdrantPatternRetrievalBackend implements PatternRetrievalBackend {

	private const DEFAULT_SEMANTIC_LIMIT    = 8;
	private const DEFAULT_STRUCTURAL_LIMIT  = 6;
	private const FILTERED_SEMANTIC_LIMIT   = 24;
	private const FILTERED_STRUCTURAL_LIMIT = 18;

	/**
	 * @param string[]             $visible_pattern_names
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $context
	 * @return array<int, array{payload: array<string, mixed>, score: float}>|\WP_Error
	 */
	public function search( string $query, array $visible_pattern_names, array $state, array $context ): array|\WP_Error {
		$query_vector = EmbeddingClient::embed( $query );

		if ( is_wp_error( $query_vector ) ) {
			return $query_vector;
		}

		$active_signature    = EmbeddingClient::build_signature_for_dimension( count( $query_vector ) );
		$expected_collection = QdrantClient::get_collection_name( $active_signature );

		if (
			(string) ( $state['embedding_signature'] ?? '' ) !== (string) $active_signature['signature_hash']
			|| (string) ( $state['qdrant_collection'] ?? '' ) !== $expected_collection
		) {
			PatternIndex::mark_stale(
				[
					'embedding_signature_changed',
					'collection_name_changed',
				]
			);

			PatternIndex::schedule_sync();

			return new \WP_Error(
				'index_warming',
				'Pattern catalog is rebuilding because the active embedding signature changed. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
				[ 'status' => 503 ]
			);
		}

		$collection_validation = QdrantClient::validate_collection_compatibility(
			(string) $state['qdrant_collection'],
			count( $query_vector )
		);

		if ( is_wp_error( $collection_validation ) ) {
			if ( 'qdrant_collection_missing' === $collection_validation->get_error_code() ) {
				PatternIndex::mark_stale( [ 'collection_missing' ] );
				PatternIndex::schedule_sync();

				return new \WP_Error(
					'index_warming',
					'Pattern catalog is rebuilding because the active Qdrant collection is missing. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
					[ 'status' => 503 ]
				);
			}

			if ( 'qdrant_collection_size_mismatch' === $collection_validation->get_error_code() ) {
				PatternIndex::mark_stale( [ 'collection_size_mismatch' ] );
				PatternIndex::schedule_sync();

				return new \WP_Error(
					'index_warming',
					'Pattern catalog is rebuilding because the active Qdrant collection is incompatible with the current embedding size. Try again shortly or run Sync Pattern Catalog from Settings > Flavor Agent.',
					[ 'status' => 503 ]
				);
			}

			return $collection_validation;
		}

		$visible_lookup   = array_fill_keys( $visible_pattern_names, true );
		$semantic_limit   = $visible_lookup ? self::FILTERED_SEMANTIC_LIMIT : self::DEFAULT_SEMANTIC_LIMIT;
		$structural_limit = $visible_lookup ? self::FILTERED_STRUCTURAL_LIMIT : self::DEFAULT_STRUCTURAL_LIMIT;
		$collection_name  = (string) $state['qdrant_collection'];
		$pass_a           = QdrantClient::search(
			$query_vector,
			$semantic_limit,
			[],
			$collection_name
		);

		if ( is_wp_error( $pass_a ) ) {
			return $pass_a;
		}

		$pass_b         = [];
		$should_clauses = is_array( $context['structuralShouldClauses'] ?? null )
			? $context['structuralShouldClauses']
			: [];

		if ( [] !== $should_clauses ) {
			$pass_b = QdrantClient::search(
				$query_vector,
				$structural_limit,
				[ 'should' => $should_clauses ],
				$collection_name
			);

			if ( is_wp_error( $pass_b ) ) {
				return $pass_b;
			}
		}

		return $this->dedupe_points( array_merge( $pass_a, $pass_b ) );
	}

	/**
	 * @param array<int, mixed> $points
	 * @return array<int, array{payload: array<string, mixed>, score: float}>
	 */
	private function dedupe_points( array $points ): array {
		$candidates = [];

		foreach ( $points as $point ) {
			if ( ! is_array( $point ) ) {
				continue;
			}

			$payload = is_array( $point['payload'] ?? null ) ? $point['payload'] : [];
			$name    = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
			$score   = isset( $point['score'] ) && is_numeric( $point['score'] ) ? (float) $point['score'] : 0.0;

			if ( '' === $name ) {
				continue;
			}

			if ( ! isset( $candidates[ $name ] ) || $candidates[ $name ]['score'] < $score ) {
				$candidates[ $name ] = [
					'payload' => $payload,
					'score'   => $score,
				];
			}
		}

		return array_values( $candidates );
	}
}
