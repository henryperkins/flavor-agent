<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

final class QdrantClient {

	private const COLLECTION_PREFIX      = 'flavor-agent-patterns';
	private const COLLECTION_HASH_LENGTH = 16;
	private const REQUEST_TIMEOUT        = 10;
	private const HEALTH_PROBES          = [ 'healthz', 'livez', 'readyz' ];
	private const OPTIMIZATION_FLAGS     = [ 'queued', 'completed', 'idle_segments' ];

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
		$connection = self::connection( $base_url, $api_key );

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$response = wp_remote_get(
			$connection['base_url'] . '/collections',
			[
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => [
					'api-key' => $connection['api_key'],
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
			return new \WP_Error(
				'qdrant_validation_error',
				self::extract_error_message( $data, "Qdrant validation returned HTTP {$status}" ),
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
	 * @return array{probe:string,status:int,message:string}|\WP_Error
	 */
	public static function probe_health(
		string $probe = 'readyz',
		?string $base_url = null,
		?string $api_key = null
	): array|\WP_Error {
		$probe = sanitize_key( $probe );

		if ( ! in_array( $probe, self::HEALTH_PROBES, true ) ) {
			return new \WP_Error(
				'qdrant_health_probe_invalid',
				sprintf(
					'Unsupported Qdrant health probe "%s". Expected one of: %s.',
					$probe,
					implode( ', ', self::HEALTH_PROBES )
				),
				[ 'status' => 400 ]
			);
		}

		$connection = self::connection( $base_url, $api_key );

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$response = self::request_text(
			'GET',
			$connection['base_url'] . '/' . $probe,
			"probe {$probe}",
			'qdrant_health_error',
			[],
			$connection['api_key']
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$message = trim( $response['body'] );

		return [
			'probe'   => $probe,
			'status'  => $response['status'],
			'message' => '' !== $message
				? $message
				: sprintf( 'Qdrant %s returned HTTP %d.', $probe, $response['status'] ),
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_telemetry(): array|\WP_Error {
		$base_url = self::base_url();

		if ( is_wp_error( $base_url ) ) {
			return $base_url;
		}

		$response = self::request(
			'GET',
			$base_url . '/telemetry',
			'get telemetry',
			'qdrant_telemetry_error'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = $response['body']['result'] ?? [];

		if ( is_array( $result ) ) {
			return $result;
		}

		return new \WP_Error(
			'qdrant_telemetry_parse_error',
			'Failed to parse Qdrant telemetry response.',
			[ 'status' => 502 ]
		);
	}

	/**
	 * @param string[] $detail_flags
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_collection_optimizations( string $collection_name, array $detail_flags = [] ): array|\WP_Error {
		$collection_url = self::collection_url( $collection_name );

		if ( is_wp_error( $collection_url ) ) {
			return $collection_url;
		}

		$detail_flags = self::normalize_optimization_flags( $detail_flags );

		if ( is_wp_error( $detail_flags ) ) {
			return $detail_flags;
		}

		$url = $collection_url . '/optimizations';

		if ( [] !== $detail_flags ) {
			$url .= '?with=' . rawurlencode( implode( ',', $detail_flags ) );
		}

		$response = self::request(
			'GET',
			$url,
			'get optimizations',
			'qdrant_optimizations_error'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = $response['body']['result'] ?? [];

		if ( is_array( $result ) ) {
			return $result;
		}

		return new \WP_Error(
			'qdrant_optimizations_parse_error',
			'Failed to parse Qdrant optimizations response.',
			[ 'status' => 502 ]
		);
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

	private static function base_url(): string|\WP_Error {
		$connection = self::connection();

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		return $connection['base_url'];
	}

	private static function collection_url( string $collection_name ): string|\WP_Error {
		$base_url = self::base_url();

		if ( is_wp_error( $base_url ) ) {
			return $base_url;
		}

		return $base_url . '/collections/' . $collection_name;
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

	private static function api_key( ?string $api_key = null ): string {
		return trim( (string) ( $api_key ?? get_option( 'flavor_agent_qdrant_key', '' ) ) );
	}

	/**
	 * @return array{base_url:string,api_key:string}|\WP_Error
	 */
	private static function connection( ?string $base_url = null, ?string $api_key = null ): array|\WP_Error {
		$resolved_base_url = trim( (string) ( $base_url ?? get_option( 'flavor_agent_qdrant_url', '' ) ) );
		$resolved_api_key  = self::api_key( $api_key );

		if ( '' === $resolved_base_url || '' === $resolved_api_key ) {
			return new \WP_Error(
				'missing_credentials',
				'Qdrant credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		return [
			'base_url' => rtrim( $resolved_base_url, '/' ),
			'api_key'  => $resolved_api_key,
		];
	}

	/**
	 * @param string[] $detail_flags
	 * @return string[]|\WP_Error
	 */
	private static function normalize_optimization_flags( array $detail_flags ): array|\WP_Error {
		$normalized_flags = [];

		foreach ( $detail_flags as $detail_flag ) {
			$candidate = sanitize_key( (string) $detail_flag );

			if ( ! in_array( $candidate, self::OPTIMIZATION_FLAGS, true ) ) {
				return new \WP_Error(
					'qdrant_optimizations_invalid_detail_flag',
					sprintf(
						'Unsupported Qdrant optimization detail flag "%s". Expected one of: %s.',
						$candidate,
						implode( ', ', self::OPTIMIZATION_FLAGS )
					),
					[ 'status' => 400 ]
				);
			}

			if ( ! in_array( $candidate, $normalized_flags, true ) ) {
				$normalized_flags[] = $candidate;
			}
		}

		return $normalized_flags;
	}

	private static function extract_error_message( mixed $payload, string $fallback ): string {
		if ( is_array( $payload ) ) {
			$message = $payload['status']['error'] ?? $payload['error'] ?? $payload['message'] ?? null;

			return is_string( $message ) && '' !== trim( $message )
				? $message
				: $fallback;
		}

		if ( '' === trim( $payload ) ) {
			return $fallback;
		}

		$data = json_decode( $payload, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $data ) ) {
			return self::extract_error_message( $data, $fallback );
		}

		$message = trim( wp_strip_all_tags( $payload ) );

		return '' !== $message ? $message : $fallback;
	}

	/**
	 * @return array{method:string,timeout:int,headers:array<string,string>}
	 */
	private static function request_args( string $method, ?string $api_key = null ): array {
		return [
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => [
				'api-key' => self::api_key( $api_key ),
			],
		];
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
		array $accepted_statuses = [],
		?string $api_key = null
	): array|\WP_Error {
		$args = self::request_args( $method, $api_key );

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

			return BaseHttpClient::build_retryable_rate_limit_error(
				'qdrant_rate_limited',
				self::extract_error_message( $raw_body, "Qdrant {$operation} is temporarily rate limited." ),
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
			$message = self::extract_error_message( $data, "Qdrant {$operation} returned HTTP {$status}" );

			if ( $status === 400 && str_contains( strtolower( $message ), 'already exists' ) ) {
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

	/**
	 * @return array{status:int,body:string}|\WP_Error
	 */
	private static function request_text(
		string $method,
		string $url,
		string $operation,
		string $error_code,
		array $accepted_statuses = [],
		?string $api_key = null
	): array|\WP_Error {
		$response = wp_remote_request( $url, self::request_args( $method, $api_key ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status   = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );

		if ( 429 === $status ) {
			return BaseHttpClient::build_retryable_rate_limit_error(
				'qdrant_rate_limited',
				self::extract_error_message( $raw_body, "Qdrant {$operation} is temporarily rate limited." ),
				wp_remote_retrieve_header( $response, 'retry-after' )
			);
		}

		$is_success = empty( $accepted_statuses )
			? $status >= 200 && $status < 300
			: in_array( $status, $accepted_statuses, true );

		if ( ! $is_success ) {
			return new \WP_Error(
				$error_code,
				self::extract_error_message( $raw_body, "Qdrant {$operation} returned HTTP {$status}" ),
				[ 'status' => 502 ]
			);
		}

		return [
			'status' => $status,
			'body'   => $raw_body,
		];
	}
}
