<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

final class QdrantClient {

	private const COLLECTION_PREFIX      = 'flavor-agent-patterns';
	private const COLLECTION_HASH_LENGTH = 16;

	/**
	 * @param array{signature_hash?: string}|null $embedding_signature
	 */
	public static function get_collection_name( ?array $embedding_signature = null ): string {
		$scope      = [
			'home_url'    => untrailingslashit( home_url( '/' ) ),
			'blog_id'     => (string) get_current_blog_id(),
			'environment' => wp_get_environment_type(),
		];
		$scope_json = wp_json_encode( $scope );
		$hash       = substr(
			hash( 'sha256', false !== $scope_json ? $scope_json : '' ),
			0,
			self::COLLECTION_HASH_LENGTH
		);
		$signature  = EmbeddingSignature::short_hash(
			$embedding_signature ?? EmbeddingSignature::from_runtime( 0 )
		);

		return self::COLLECTION_PREFIX . '-' . $hash . '-' . $signature;
	}

	public static function validate_configuration(
		?string $base_url = null,
		?string $api_key = null
	): true|\WP_Error {
		$base_url = trim( (string) ( $base_url ?? get_option( 'flavor_agent_qdrant_url', '' ) ) );
		$api_key  = trim( (string) ( $api_key ?? get_option( 'flavor_agent_qdrant_key', '' ) ) );

		if ( '' === $base_url || '' === $api_key ) {
			return new \WP_Error(
				'missing_credentials',
				'Qdrant credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		$response = wp_remote_get(
			rtrim( $base_url, '/' ) . '/collections',
			[
				'timeout' => 10,
				'headers' => [
					'api-key' => $api_key,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data )
				? ( $data['status']['error'] ?? $data['error'] ?? $data['message'] ?? "Qdrant validation returned HTTP {$status}" )
				: "Qdrant validation returned HTTP {$status}";

			return new \WP_Error(
				'qdrant_validation_error',
				is_string( $message ) ? $message : "Qdrant validation returned HTTP {$status}",
				[ 'status' => 400 ]
			);
		}

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error(
				'qdrant_validation_parse_error',
				'Failed to parse Qdrant validation response.',
				[ 'status' => 400 ]
			);
		}

		// Ensure the response looks like a Qdrant /collections payload.
		if (
			! isset( $data['status'] ) ||
			'ok' !== $data['status'] ||
			! isset( $data['result'] ) ||
			! is_array( $data['result'] ) ||
			! array_key_exists( 'collections', $data['result'] ) ||
			! is_array( $data['result']['collections'] )
		) {
			return new \WP_Error(
				'qdrant_validation_unexpected_response',
				'Qdrant validation response did not contain the expected collections list.',
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Ensure the Qdrant collection exists, creating it if missing.
	 */
	public static function ensure_collection( string $collection_name, int $vector_size ): true|\WP_Error {
		$url = self::collection_url( $collection_name );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$collection = self::get_collection_definition( $collection_name );

		if ( is_wp_error( $collection ) ) {
			if ( 'qdrant_collection_missing' !== $collection->get_error_code() ) {
				return $collection;
			}

			$created = self::request(
				'PUT',
				$url,
				'create collection',
				'qdrant_create_error',
				[
					'vectors' => [
						'size'     => $vector_size,
						'distance' => 'Cosine',
					],
				]
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
		} else {
			$existing_dimension = self::extract_collection_vector_size( $collection );

			if ( is_wp_error( $existing_dimension ) ) {
				return $existing_dimension;
			}

			if ( $existing_dimension !== $vector_size ) {
				return self::build_collection_size_mismatch_error( $collection_name, $existing_dimension, $vector_size );
			}
		}

		return self::ensure_payload_indexes( $collection_name );
	}

	public static function validate_collection_compatibility( string $collection_name, int $vector_size ): true|\WP_Error {
		$collection = self::get_collection_definition( $collection_name );

		if ( is_wp_error( $collection ) ) {
			return $collection;
		}

		$existing_dimension = self::extract_collection_vector_size( $collection );

		if ( is_wp_error( $existing_dimension ) ) {
			return $existing_dimension;
		}

		if ( $existing_dimension !== $vector_size ) {
			return self::build_collection_size_mismatch_error( $collection_name, $existing_dimension, $vector_size );
		}

		return true;
	}

	/**
	 * Upsert points into the collection.
	 *
	 * @param array[] $points Each with id, vector, payload.
	 */
	public static function upsert_points( array $points, string $collection_name ): true|\WP_Error {
		$url = self::collection_url( $collection_name );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = self::request(
			'PUT',
			$url . '/points',
			'upsert points',
			'qdrant_upsert_error',
			[ 'points' => $points ]
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Delete points by ID.
	 *
	 * @param string[] $ids UUIDs to remove.
	 */
	public static function delete_points( array $ids, string $collection_name ): true|\WP_Error {
		$url = self::collection_url( $collection_name );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = self::request(
			'POST',
			$url . '/points/delete',
			'delete points',
			'qdrant_delete_error',
			[ 'points' => $ids ]
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Vector search with optional filter.
	 *
	 * @param float[] $vector Query vector.
	 * @param int     $limit  Max results.
	 * @param array   $filter Qdrant filter.
	 * @return array|\WP_Error Array of point objects with score and payload.
	 */
	public static function search( array $vector, int $limit, array $filter = [], ?string $collection_name = null ): array|\WP_Error {
		$url = self::collection_url(
			$collection_name ?? self::get_collection_name( EmbeddingSignature::from_runtime( count( $vector ) ) )
		);

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$body = [
			'query'        => $vector,
			'limit'        => $limit,
			'with_payload' => true,
		];

		if ( ! empty( $filter ) ) {
			$body['filter'] = $filter;
		}

		$response = self::request(
			'POST',
			$url . '/points/query',
			'search',
			'qdrant_search_error',
			$body
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = $response['body']['result'] ?? [];

		if ( isset( $result['points'] ) && is_array( $result['points'] ) ) {
			return $result['points'];
		}

		if ( is_array( $result ) ) {
			return $result;
		}

		return new \WP_Error(
			'qdrant_search_parse_error',
			'Failed to parse Qdrant search response.',
			[ 'status' => 502 ]
		);
	}

	/**
	 * Scroll all point IDs with their name payload.
	 *
	 * @return array|\WP_Error Map of uuid => pattern name.
	 */
	public static function scroll_ids( string $collection_name ): array|\WP_Error {
		$url = self::collection_url( $collection_name );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$ids    = [];
		$offset = null;

		do {
			$body = [
				'limit'        => 100,
				'with_payload' => [ 'include' => [ 'name' ] ],
				'with_vector'  => false,
			];

			if ( $offset !== null ) {
				$body['offset'] = $offset;
			}

			$response = self::request(
				'POST',
				$url . '/points/scroll',
				'scroll',
				'qdrant_scroll_error',
				$body
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$result = $response['body']['result'] ?? [];
			if ( ! is_array( $result ) ) {
				return new \WP_Error(
					'qdrant_scroll_parse_error',
					'Failed to parse Qdrant scroll response.',
					[ 'status' => 502 ]
				);
			}

			$points = $result['points'] ?? [];
			$offset = $result['next_page_offset'] ?? null;

			foreach ( $points as $point ) {
				$ids[ $point['id'] ] = $point['payload']['name'] ?? '';
			}
		} while ( $offset !== null );

		return $ids;
	}

	private static function ensure_payload_indexes( string $collection_name ): true|\WP_Error {
		// Leave the content payload unindexed by only creating keyword indexes
		// for the structural filter fields the recommendation flow needs.
		foreach ( [ 'blockTypes', 'templateTypes', 'categories', 'traits' ] as $field ) {
			$result = self::create_payload_index( $collection_name, $field, 'keyword' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	private static function create_payload_index( string $collection_name, string $field, string $type ): true|\WP_Error {
		$url = self::collection_url( $collection_name );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = self::request(
			'PUT',
			$url . '/index',
			"create payload index for {$field}",
			'qdrant_index_error',
			[
				'field_name'   => $field,
				'field_schema' => $type,
			],
			[ 200, 201, 202, 409 ]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	private static function collection_url( string $collection_name ): string|\WP_Error {
		$base = get_option( 'flavor_agent_qdrant_url', '' );
		$key  = get_option( 'flavor_agent_qdrant_key', '' );

		if ( empty( $base ) || empty( $key ) ) {
			return new \WP_Error(
				'missing_credentials',
				'Qdrant credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		return rtrim( $base, '/' ) . '/collections/' . $collection_name;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function get_collection_definition( string $collection_name ): array|\WP_Error {
		$url = self::collection_url( $collection_name );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$lookup = self::request(
			'GET',
			$url,
			'get collection',
			'qdrant_get_collection_error',
			null,
			[ 200, 404 ]
		);

		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		if ( 404 === $lookup['status'] ) {
			return new \WP_Error(
				'qdrant_collection_missing',
				sprintf( 'Qdrant collection %s is missing.', $collection_name ),
				[
					'status'          => 404,
					'collection_name' => $collection_name,
				]
			);
		}

		return is_array( $lookup['body']['result'] ?? null )
			? $lookup['body']['result']
			: [];
	}

	private static function build_collection_size_mismatch_error( string $collection_name, int $actual_dimension, int $expected_dimension ): \WP_Error {
		return new \WP_Error(
			'qdrant_collection_size_mismatch',
			sprintf(
				'Qdrant collection %1$s uses %2$d dimensions but the active embedding configuration requires %3$d.',
				$collection_name,
				$actual_dimension,
				$expected_dimension
			),
			[
				'status'             => 409,
				'collection_name'    => $collection_name,
				'expected_dimension' => $expected_dimension,
				'actual_dimension'   => $actual_dimension,
			]
		);
	}

	/**
	 * @param array<string, mixed> $collection
	 */
	private static function extract_collection_vector_size( array $collection ): int|\WP_Error {
		$vector_config = $collection['config']['params']['vectors'] ?? null;

		if ( is_array( $vector_config ) && isset( $vector_config['size'] ) ) {
			return (int) $vector_config['size'];
		}

		if ( is_array( $vector_config ) ) {
			foreach ( $vector_config as $definition ) {
				if ( is_array( $definition ) && isset( $definition['size'] ) ) {
					return (int) $definition['size'];
				}
			}
		}

		return new \WP_Error(
			'qdrant_collection_parse_error',
			'Failed to determine the Qdrant collection vector size.',
			[ 'status' => 502 ]
		);
	}

	private static function api_key(): string {
		return get_option( 'flavor_agent_qdrant_key', '' );
	}

	/**
	 * @return array{status:int,body:array}|\WP_Error
	 */
	private static function request(
		string $method,
		string $url,
		string $operation,
		string $error_code,
		?array $body = null,
		array $accepted_statuses = []
	): array|\WP_Error {
		$args = [
			'method'  => $method,
			'timeout' => 10,
			'headers' => [
				'api-key' => self::api_key(),
			],
		];

		if ( $body !== null ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 429 === $status ) {
			$raw_body = wp_remote_retrieve_body( $response );
			$data     = json_decode( $raw_body, true );
			$message  = is_array( $data )
				? ( $data['status']['error'] ?? $data['error'] ?? $data['message'] ?? "Qdrant {$operation} is temporarily rate limited." )
				: "Qdrant {$operation} is temporarily rate limited.";

			return BaseHttpClient::build_retryable_rate_limit_error(
				'qdrant_rate_limited',
				(string) $message,
				wp_remote_retrieve_header( $response, 'retry-after' )
			);
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$data     = [];

		if ( $raw_body !== '' ) {
			$data = json_decode( $raw_body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new \WP_Error(
					'qdrant_parse_error',
					"Failed to parse Qdrant {$operation} response.",
					[ 'status' => 502 ]
				);
			}
		}

		$is_success = empty( $accepted_statuses )
			? $status >= 200 && $status < 300
			: in_array( $status, $accepted_statuses, true );

		if ( ! $is_success ) {
			$message = $data['status']['error'] ?? $data['error'] ?? $data['message'] ?? "Qdrant {$operation} returned HTTP {$status}";

			if ( $status === 400 && is_string( $message ) && str_contains( strtolower( $message ), 'already exists' ) ) {
				return [
					'status' => $status,
					'body'   => $data,
				];
			}

			return new \WP_Error( $error_code, $message, [ 'status' => 502 ] );
		}

		return [
			'status' => $status,
			'body'   => is_array( $data ) ? $data : [],
		];
	}
}
