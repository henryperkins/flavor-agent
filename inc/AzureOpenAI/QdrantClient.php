<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

final class QdrantClient {

	private const COLLECTION_PREFIX      = 'flavor-agent-patterns';
	private const COLLECTION_HASH_LENGTH = 16;
	private const VECTOR_SIZE            = 3072;

	public static function get_collection_name(): string {
		$scope = [
			'home_url'    => untrailingslashit( home_url( '/' ) ),
			'blog_id'     => (string) get_current_blog_id(),
			'environment' => wp_get_environment_type(),
		];
		$hash  = substr(
			hash( 'sha256', wp_json_encode( $scope ) ?: '' ),
			0,
			self::COLLECTION_HASH_LENGTH
		);

		return self::COLLECTION_PREFIX . '-' . $hash;
	}

	/**
	 * Ensure the Qdrant collection exists, creating it if missing.
	 */
	public static function ensure_collection(): true|\WP_Error {
		$url = self::collection_url();

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

		if ( $lookup['status'] === 404 ) {
			$created = self::request(
				'PUT',
				$url,
				'create collection',
				'qdrant_create_error',
				[
					'vectors' => [
						'size'     => self::VECTOR_SIZE,
						'distance' => 'Cosine',
					],
				]
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
		}

		return self::ensure_payload_indexes();
	}

	/**
	 * Upsert points into the collection.
	 *
	 * @param array[] $points Each with id, vector, payload.
	 */
	public static function upsert_points( array $points ): true|\WP_Error {
		$url = self::collection_url();

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
	public static function delete_points( array $ids ): true|\WP_Error {
		$url = self::collection_url();

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
	public static function search( array $vector, int $limit, array $filter = [] ): array|\WP_Error {
		$url = self::collection_url();

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
	public static function scroll_ids(): array|\WP_Error {
		$url = self::collection_url();

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

	private static function ensure_payload_indexes(): true|\WP_Error {
		// Leave the content payload unindexed by only creating keyword indexes
		// for the structural filter fields the recommendation flow needs.
		foreach ( [ 'blockTypes', 'templateTypes', 'categories' ] as $field ) {
			$result = self::create_payload_index( $field, 'keyword' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	private static function create_payload_index( string $field, string $type ): true|\WP_Error {
		$url = self::collection_url();

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

	private static function collection_url(): string|\WP_Error {
		$base = get_option( 'flavor_agent_qdrant_url', '' );
		$key  = get_option( 'flavor_agent_qdrant_key', '' );

		if ( empty( $base ) || empty( $key ) ) {
			return new \WP_Error(
				'missing_credentials',
				'Qdrant credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		return rtrim( $base, '/' ) . '/collections/' . self::get_collection_name();
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
		array $accepted_statuses = [],
		bool $is_retry = false
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

		if ( $status === 429 && ! $is_retry ) {
			$retry_after = (int) ( wp_remote_retrieve_header( $response, 'retry-after' ) ?: 2 );
			sleep( min( $retry_after, 10 ) );

			return self::request( $method, $url, $operation, $error_code, $body, $accepted_statuses, true );
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
