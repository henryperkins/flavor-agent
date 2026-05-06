<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Embeddings\BaseHttpClient;

final class PatternSearchInstanceManager extends BaseHttpClient {

	public const OWNER_MARKER_NAME = '__flavor_agent_owner__';

	private const REQUEST_TIMEOUT                      = 20;
	private const SITE_HASH_LENGTH                     = 16;
	private const SUPPORTED_AI_SEARCH_EMBEDDING_MODELS = [
		'@cf/qwen/qwen3-embedding-0.6b',
		'@cf/baai/bge-m3',
		'@cf/baai/bge-large-en-v1.5',
		'@cf/google/embeddinggemma-300m',
		'google-ai-studio/gemini-embedding-001',
		'google-ai-studio/gemini-embedding-2-preview',
		'openai/text-embedding-3-small',
		'openai/text-embedding-3-large',
	];

	public static function managed_instance_id(): string {
		return 'flavor-agent-patterns-' . self::site_hash();
	}

	public static function site_hash(): string {
		$url = function_exists( 'home_url' ) ? home_url() : 'local';

		return substr( hash( 'sha256', strtolower( trim( (string) $url ) ) ), 0, self::SITE_HASH_LENGTH );
	}

	public static function credential_signature( string $account_id, string $api_token, string $embedding_model ): string {
		$payload = [
			self::site_hash(),
			trim( sanitize_text_field( $account_id ) ),
			wp_hash( trim( sanitize_text_field( $api_token ) ) ),
			self::normalize_embedding_model_for_ai_search( $embedding_model ),
			Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE,
		];

		return hash( 'sha256', implode( "\n", $payload ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function build_create_payload( string $embedding_model ): array {
		return [
			'id'                     => self::managed_instance_id(),
			'embedding_model'        => self::normalize_embedding_model_for_ai_search( $embedding_model ),
			'chunk'                  => true,
			'chunk_size'             => 1024,
			'chunk_overlap'          => 15,
			'custom_metadata'        => self::expected_custom_metadata(),
			'fusion_method'          => 'rrf',
			'index_method'           => [
				'keyword' => true,
				'vector'  => true,
			],
			'indexing_options'       => [
				'keyword_tokenizer' => 'porter',
			],
			'max_num_results'        => 50,
			'retrieval_options'      => [
				'keyword_match_mode' => 'or',
			],
			'rewrite_query'          => false,
			'reranking'              => false,
			'cache'                  => false,
			'public_endpoint_params' => [
				'enabled'                   => false,
				'search_endpoint'           => [ 'disabled' => true ],
				'chat_completions_endpoint' => [ 'disabled' => true ],
				'mcp'                       => [ 'disabled' => true ],
			],
		];
	}

	/**
	 * @return array{instance_id: string, status: string}|\WP_Error
	 */
	public static function ensure_managed_instance( string $account_id, string $api_token, string $embedding_model ): array|\WP_Error {
		$config = self::normalize_credentials( $account_id, $api_token );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$instances = self::list_instances( $config['account_id'], $config['api_token'] );

		if ( is_wp_error( $instances ) ) {
			return $instances;
		}

		foreach ( $instances as $instance ) {
			$compatible = self::assert_compatible_instance( $instance, $embedding_model );

			if ( is_wp_error( $compatible ) ) {
				return $compatible;
			}

			$marker = self::validate_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

			if ( is_wp_error( $marker ) ) {
				return $marker;
			}

			return [
				'instance_id' => self::managed_instance_id(),
				'status'      => 'adopted',
			];
		}

		$created = self::create_instance( $config['account_id'], $config['api_token'], $embedding_model );

		if ( is_wp_error( $created ) ) {
			if ( self::is_create_conflict_error( $created ) ) {
					return self::try_adopt_after_create_conflict( $config['account_id'], $config['api_token'], $embedding_model );
			}

			return $created;
		}

		$marker = self::write_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

		if ( is_wp_error( $marker ) ) {
			return $marker;
		}

		$validated_marker = self::validate_owner_marker( $config['account_id'], $config['api_token'], self::managed_instance_id() );

		if ( is_wp_error( $validated_marker ) ) {
			return $validated_marker;
		}

		return [
			'instance_id' => self::managed_instance_id(),
			'status'      => 'created',
		];
	}

	/**
	 * @return array<int, array{field_name: string, data_type: string}>
	 */
	private static function expected_custom_metadata(): array {
		return [
			[
				'field_name' => 'pattern_name',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'candidate_type',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'source',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'synced_id',
				'data_type'  => 'text',
			],
			[
				'field_name' => 'public_safe',
				'data_type'  => 'boolean',
			],
		];
	}

	public static function normalize_embedding_model_for_ai_search( string $embedding_model ): string {
		$embedding_model = trim( sanitize_text_field( $embedding_model ) );

		return in_array( $embedding_model, self::SUPPORTED_AI_SEARCH_EMBEDDING_MODELS, true )
			? $embedding_model
			: WorkersAIEmbeddingConfiguration::DEFAULT_MODEL;
	}

	/**
	 * @return array{account_id: string, api_token: string}|\WP_Error
	 */
	private static function normalize_credentials( string $account_id, string $api_token ): array|\WP_Error {
		$account_id = trim( sanitize_text_field( $account_id ) );
		$api_token  = trim( sanitize_text_field( $api_token ) );

		if ( '' === $account_id || '' === $api_token ) {
			return new \WP_Error(
				'missing_cloudflare_pattern_ai_search_credentials',
				'Cloudflare AI Search Pattern Storage needs the Embedding Model account ID and API token.',
				[ 'status' => 400 ]
			);
		}

		return [
			'account_id' => $account_id,
			'api_token'  => $api_token,
		];
	}

	private static function instances_url( string $account_id ): string {
		return sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/namespaces/%s/instances',
			rawurlencode( $account_id ),
			rawurlencode( Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE )
		);
	}

	private static function instance_items_url( string $account_id, string $instance_id ): string {
		return self::instances_url( $account_id ) . '/' . rawurlencode( $instance_id ) . '/items';
	}

	/**
	 * @return array<string, string>
	 */
	private static function authorization_headers( string $api_token ): array {
		return [ 'Authorization' => 'Bearer ' . $api_token ];
	}

	/**
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function list_instances( string $account_id, string $api_token ): array|\WP_Error {
		$instances = [];
		$page      = 1;
		$per_page  = 100;

		do {
			$response = self::request_json(
				add_query_arg(
					[
						'search'   => self::managed_instance_id(),
						'page'     => $page,
						'per_page' => $per_page,
					],
					self::instances_url( $account_id )
				),
				[
					'method'  => 'GET',
					'headers' => self::authorization_headers( $api_token ),
				],
				'Cloudflare AI Search instance list',
				self::REQUEST_TIMEOUT
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
				return new \WP_Error(
					'cloudflare_pattern_ai_search_instance_list_error',
					'Cloudflare AI Search instance list failed.',
					[ 'status' => 502 ]
				);
			}

			$result = is_array( $response['data']['result'] ?? null ) ? $response['data']['result'] : [];
			foreach ( $result as $instance ) {
				if ( is_array( $instance ) && self::managed_instance_id() === (string) ( $instance['id'] ?? '' ) ) {
					$instances[] = $instance;
				}
			}

			$result_info = is_array( $response['data']['result_info'] ?? null ) ? $response['data']['result_info'] : [];
			$total_count = max( 0, (int) ( $result_info['total_count'] ?? count( $result ) ) );
			$page_size   = max( 1, (int) ( $result_info['per_page'] ?? $per_page ) );
			$total_pages = max( 1, (int) ceil( $total_count / $page_size ) );
			++$page;
		} while ( $page <= $total_pages && [] === $instances );

		return $instances;
	}

	private static function create_instance( string $account_id, string $api_token, string $embedding_model ): true|\WP_Error {
		$response = self::post_json(
			self::instances_url( $account_id ),
			array_merge( self::authorization_headers( $api_token ), [ 'Content-Type' => 'application/json' ] ),
			self::encode_json( self::build_create_payload( $embedding_model ) ),
			'Cloudflare AI Search instance create',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! in_array( $response['status'], [ 200, 201 ], true ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_instance_create_error',
				'Cloudflare AI Search managed pattern index could not be created.',
				[
					'status'      => 502,
					'http_status' => $response['status'],
				]
			);
		}

		return true;
	}

	private static function encode_json( mixed $value ): string {
		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @return array<int, array{field_name: string, data_type: string}>
	 */
	private static function normalize_custom_metadata_schema( mixed $schema ): array {
		if ( ! is_array( $schema ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $schema as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$normalized[] = [
				'field_name' => strtolower( sanitize_key( (string) ( $field['field_name'] ?? '' ) ) ),
				'data_type'  => strtolower( sanitize_key( (string) ( $field['data_type'] ?? '' ) ) ),
			];
		}

		usort(
			$normalized,
			static fn( array $a, array $b ): int => strcmp( $a['field_name'], $b['field_name'] )
		);

		return $normalized;
	}

	private static function assert_compatible_instance( array $instance, string $embedding_model ): true|\WP_Error {
		$actual   = self::normalize_custom_metadata_schema( $instance['custom_metadata'] ?? [] );
		$expected = self::normalize_custom_metadata_schema( self::expected_custom_metadata() );

		if ( $actual !== $expected ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_incompatible_schema',
				'The existing Cloudflare AI Search managed pattern index does not use the Flavor Agent metadata schema.',
				[ 'status' => 409 ]
			);
		}

		$actual_model_raw = trim( sanitize_text_field( (string) ( $instance['embedding_model'] ?? '' ) ) );
		$actual_model     = self::normalize_embedding_model_for_ai_search( $actual_model_raw );
		$expected_model   = self::normalize_embedding_model_for_ai_search( $embedding_model );

		if ( '' === $actual_model_raw || $actual_model !== $expected_model ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_embedding_model_mismatch',
				'The existing Cloudflare AI Search managed pattern index uses a different embedding model.',
				[
					'status'         => 409,
					'expected_model' => $expected_model,
					'actual_model'   => $actual_model_raw,
				]
			);
		}

		return true;
	}

	/**
	 * @return array{pattern_name: string, candidate_type: string, source: string, synced_id: string, public_safe: bool}
	 */
	private static function owner_marker_metadata(): array {
		return [
			'pattern_name'   => self::OWNER_MARKER_NAME,
			'candidate_type' => 'flavor_agent_owner',
			'source'         => 'flavor_agent',
			'synced_id'      => self::site_hash(),
			'public_safe'    => true,
		];
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private static function owner_marker_filter(): array {
		return [
			'pattern_name'   => [ '$eq' => self::OWNER_MARKER_NAME ],
			'candidate_type' => [ '$eq' => 'flavor_agent_owner' ],
			'source'         => [ '$eq' => 'flavor_agent' ],
			'synced_id'      => [ '$eq' => self::site_hash() ],
		];
	}

	private static function owner_marker_list_url( string $account_id, string $instance_id ): string {
		return add_query_arg(
			[
				'metadata_filter' => self::encode_json( self::owner_marker_filter() ),
				'per_page'        => 5,
			],
			self::instance_items_url( $account_id, $instance_id )
		);
	}

	private static function write_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
		$response = self::post_multipart(
			self::instance_items_url( $account_id, $instance_id ),
			self::authorization_headers( $api_token ),
			[
				'metadata'            => self::encode_json( self::owner_marker_metadata() ),
				'wait_for_completion' => 'true',
			],
			[
				'name'         => 'file',
				'filename'     => self::OWNER_MARKER_NAME . '.md',
				'contents'     => "# Flavor Agent Owner\n\nSite hash: " . self::site_hash() . "\n",
				'content_type' => 'text/markdown; charset=UTF-8',
			],
			'Cloudflare AI Search owner marker upload',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! in_array( $response['status'], [ 200, 201 ], true ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_owner_marker_error',
				'Flavor Agent could not write the Cloudflare AI Search owner marker.',
				[ 'status' => 502 ]
			);
		}

		return true;
	}

	private static function validate_owner_marker( string $account_id, string $api_token, string $instance_id ): true|\WP_Error {
		$response = self::request_json(
			self::owner_marker_list_url( $account_id, $instance_id ),
			[
				'method'  => 'GET',
				'headers' => self::authorization_headers( $api_token ),
			],
			'Cloudflare AI Search owner marker read',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_owner_marker_error',
				'Flavor Agent could not read the Cloudflare AI Search owner marker.',
				[ 'status' => 502 ]
			);
		}

		$items = is_array( $response['data']['result'] ?? null ) ? $response['data']['result'] : [];

		if ( [] === $items ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_owner_marker_missing',
				'The existing Cloudflare AI Search managed pattern index is missing the Flavor Agent owner marker.',
				[ 'status' => 409 ]
			);
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$metadata = is_array( $item['metadata'] ?? null )
				? $item['metadata']
				: ( is_array( $item['item']['metadata'] ?? null ) ? $item['item']['metadata'] : [] );

			if ( self::normalize_owner_marker_metadata( $metadata ) === self::owner_marker_metadata() ) {
				return true;
			}
		}

		return new \WP_Error(
			'cloudflare_pattern_ai_search_owner_marker_mismatch',
			'The existing Cloudflare AI Search managed pattern index belongs to a different Flavor Agent install.',
			[ 'status' => 409 ]
		);
	}

	/**
	 * @return array{pattern_name: string, candidate_type: string, source: string, synced_id: string, public_safe: bool}
	 */
	private static function normalize_owner_marker_metadata( array $metadata ): array {
		return [
			'pattern_name'   => sanitize_text_field( (string) ( $metadata['pattern_name'] ?? '' ) ),
			'candidate_type' => sanitize_text_field( (string) ( $metadata['candidate_type'] ?? '' ) ),
			'source'         => sanitize_text_field( (string) ( $metadata['source'] ?? '' ) ),
			'synced_id'      => sanitize_text_field( (string) ( $metadata['synced_id'] ?? '' ) ),
			'public_safe'    => rest_sanitize_boolean( $metadata['public_safe'] ?? false ),
		];
	}

	private static function is_create_conflict_error( \WP_Error $error ): bool {
		$data = $error->get_error_data();

		return is_array( $data ) && 409 === (int) ( $data['http_status'] ?? 0 );
	}

	/**
	 * @return array{instance_id: string, status: string}|\WP_Error
	 */
	private static function try_adopt_after_create_conflict( string $account_id, string $api_token, string $embedding_model ): array|\WP_Error {
		$instances = self::list_instances( $account_id, $api_token );

		if ( is_wp_error( $instances ) ) {
			return $instances;
		}

		foreach ( $instances as $instance ) {
			$compatible = self::assert_compatible_instance( $instance, $embedding_model );

			if ( is_wp_error( $compatible ) ) {
				return $compatible;
			}

			$marker = self::validate_owner_marker( $account_id, $api_token, self::managed_instance_id() );

			if ( is_wp_error( $marker ) ) {
				return $marker;
			}

			return [
				'instance_id' => self::managed_instance_id(),
				'status'      => 'adopted_after_conflict',
			];
		}

		return new \WP_Error(
			'cloudflare_pattern_ai_search_instance_create_conflict',
			'Cloudflare reported a managed pattern index conflict, but the instance could not be adopted safely.',
			[ 'status' => 409 ]
		);
	}

	private function __construct() {}
}
