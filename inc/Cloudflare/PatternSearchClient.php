<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\AzureOpenAI\BaseHttpClient;

final class PatternSearchClient extends BaseHttpClient {

	private const REQUEST_TIMEOUT             = 20;
	private const UPLOAD_TIMEOUT              = 60;
	private const LIST_PER_PAGE               = 100;
	private const DEFAULT_MAX_RESULTS         = 50;
	private const MAX_MAX_RESULTS             = 50;
	private const DEFAULT_MATCH_THRESHOLD     = 0.2;
	private const VALIDATION_PROBE_QUERY      = 'Flavor Agent pattern recommendation validation';
	private const VALIDATION_PROBE_PATTERN    = '__flavor_agent_validation_probe__';
	private const SCHEMA_METADATA_FIELD_NAMES = [
		'pattern_name',
		'candidate_type',
		'source',
		'synced_id',
		'public_safe',
	];

	public static function is_configured(
		?string $account_id = null,
		?string $namespace_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): bool {
		return ! is_wp_error(
			self::get_config( $account_id, $namespace_id, $instance_id, $api_token )
		);
	}

	public static function validate_configuration(
		?string $account_id = null,
		?string $namespace_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): true|\WP_Error {
		$config = self::get_config( $account_id, $namespace_id, $instance_id, $api_token );

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$result = self::request_search(
			$config,
			self::VALIDATION_PROBE_QUERY,
			[ self::VALIDATION_PROBE_PATTERN ],
			1,
			'cloudflare_pattern_ai_search_validation_error',
			'cloudflare_pattern_ai_search_validation_parse_error',
			400
		);

		if ( is_wp_error( $result ) ) {
			return self::is_schema_filter_error( $result )
				? self::build_schema_filter_error( $result )
				: $result;
		}

		return true;
	}

	/**
	 * Upload a public pattern as a markdown item in private Cloudflare AI Search.
	 *
	 * @param array<string, mixed> $pattern
	 */
	public static function upload_pattern( array $pattern, string $item_id, bool $wait = false ): true|\WP_Error {
		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$item_id = self::normalize_item_id( $item_id );

		if ( '' === $item_id ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_missing_item_id',
				'A pattern item ID is required before uploading to Cloudflare AI Search.',
				[ 'status' => 400 ]
			);
		}

		$public_safe = self::is_public_safe_pattern( $pattern );

		if ( is_wp_error( $public_safe ) ) {
			return $public_safe;
		}

		$metadata = self::build_metadata( $pattern, $item_id );
		$body     = self::build_pattern_markdown( $pattern, $metadata );

		$response = self::post_multipart_with_retry(
			$config['itemsUrl'],
			self::authorization_headers( $config['apiToken'] ),
			[
				'metadata'            => self::encode_json( $metadata ),
				'wait_for_completion' => $wait ? 'true' : 'false',
			],
			[
				'name'         => 'file',
				'filename'     => self::filename_for_item_id( $item_id ),
				'contents'     => $body,
				'content_type' => 'text/markdown; charset=UTF-8',
			],
			'Cloudflare Pattern AI Search item upload',
			self::UPLOAD_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::assert_successful_json_response(
			$response,
			'cloudflare_pattern_ai_search_upload_error',
			'cloudflare_pattern_ai_search_upload_parse_error',
			'Cloudflare Pattern AI Search upload'
		);
	}

	public static function delete_pattern( string $item_id ): true|\WP_Error {
		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$item_id = self::normalize_item_id( $item_id );

		if ( '' === $item_id ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_missing_item_id',
				'A pattern item ID is required before deleting from Cloudflare AI Search.',
				[ 'status' => 400 ]
			);
		}

		$response = self::request_with_retry(
			$config['itemsUrl'] . '/' . rawurlencode( $item_id ),
			[
				'method'  => 'DELETE',
				'headers' => self::authorization_headers( $config['apiToken'] ),
			],
			'Cloudflare Pattern AI Search item delete',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 404 === $response['status'] ) {
			return true;
		}

		return self::assert_successful_json_response(
			$response,
			'cloudflare_pattern_ai_search_delete_error',
			'cloudflare_pattern_ai_search_delete_parse_error',
			'Cloudflare Pattern AI Search delete'
		);
	}

	/**
	 * @return string[]|\WP_Error
	 */
	public static function list_pattern_item_ids(): array|\WP_Error {
		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$item_ids = [];
		$page     = 1;

		while ( true ) {
			$response = self::request_with_retry(
				self::append_query_args(
					$config['itemsUrl'],
					[
						'page'     => (string) $page,
						'per_page' => (string) self::LIST_PER_PAGE,
						'source'   => 'builtin',
					]
				),
				[
					'method'  => 'GET',
					'headers' => self::authorization_headers( $config['apiToken'] ),
				],
				'Cloudflare Pattern AI Search item list',
				self::REQUEST_TIMEOUT
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 !== $response['status'] ) {
				return self::build_http_error(
					'cloudflare_pattern_ai_search_list_error',
					$response['data'],
					$response['status'],
					502
				);
			}

			if ( JSON_ERROR_NONE !== $response['json_error'] || ! is_array( $response['data'] ) ) {
				return new \WP_Error(
					'cloudflare_pattern_ai_search_list_parse_error',
					'Failed to parse Cloudflare Pattern AI Search item list response.',
					[ 'status' => 502 ]
				);
			}

			$data        = $response['data'];
			$items       = is_array( $data['result'] ?? null ) ? $data['result'] : [];
			$result_info = is_array( $data['result_info'] ?? null ) ? $data['result_info'] : [];

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$item_id = self::normalize_item_id( (string) ( $item['id'] ?? '' ) );

				if ( '' !== $item_id ) {
					$item_ids[ $item_id ] = $item_id;
				}
			}

			$count       = max( 0, (int) ( $result_info['count'] ?? count( $items ) ) );
			$total_count = max( 0, (int) ( $result_info['total_count'] ?? count( $item_ids ) ) );
			$per_page    = max( 1, (int) ( $result_info['per_page'] ?? self::LIST_PER_PAGE ) );

			if ( 0 === $count || ( $page * $per_page ) >= $total_count ) {
				break;
			}

			++$page;
		}

		return array_values( $item_ids );
	}

	/**
	 * @param array<int, mixed> $visible_pattern_names
	 * @return array<int, array{name:string,score:float,text:string,metadata:array<string, mixed>,source:string}>|\WP_Error
	 */
	public static function search_patterns(
		string $query,
		array $visible_pattern_names,
		int $max_results = self::DEFAULT_MAX_RESULTS
	): array|\WP_Error {
		$query = sanitize_textarea_field( $query );

		if ( '' === $query ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_missing_query',
				'A pattern search query is required.',
				[ 'status' => 400 ]
			);
		}

		$visible_pattern_names = self::normalize_string_list( $visible_pattern_names );

		if ( [] === $visible_pattern_names ) {
			return [];
		}

		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$data = self::request_search(
			$config,
			$query,
			$visible_pattern_names,
			self::normalize_max_results( $max_results ),
			'cloudflare_pattern_ai_search_error',
			'cloudflare_pattern_ai_search_parse_error',
			502
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return self::normalize_chunks( self::extract_chunks( $data ), $visible_pattern_names );
	}

	/**
	 * @return array{accountId:string,namespace:string,instanceId:string,searchUrl:string,itemsUrl:string,apiToken:string}|\WP_Error
	 */
	private static function get_config(
		?string $account_id = null,
		?string $namespace_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array|\WP_Error {
		$account_id  = self::normalize_config_value(
			$account_id ?? get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID, '' )
		);
		$namespace   = self::normalize_config_value(
			$namespace_id ?? get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE, '' )
		);
		$instance_id = self::normalize_config_value(
			$instance_id ?? get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID, '' )
		);
		$api_token   = self::normalize_config_value(
			$api_token ?? get_option( Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN, '' )
		);
		$missing     = [];

		if ( '' === $account_id ) {
			$missing[] = 'account ID';
		}

		if ( '' === $namespace ) {
			$missing[] = 'namespace';
		}

		if ( '' === $instance_id ) {
			$missing[] = 'instance ID';
		}

		if ( '' === $api_token ) {
			$missing[] = 'API token';
		}

		if ( [] !== $missing ) {
			return new \WP_Error(
				'missing_cloudflare_pattern_ai_search_credentials',
				sprintf(
					'Cloudflare Pattern AI Search is not configured. Missing: %s.',
					implode( ', ', $missing )
				),
				[
					'status'  => 400,
					'missing' => $missing,
				]
			);
		}

		$instance_url = sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/namespaces/%s/instances/%s',
			rawurlencode( $account_id ),
			rawurlencode( $namespace ),
			rawurlencode( $instance_id )
		);

		return [
			'accountId'  => $account_id,
			'namespace'  => $namespace,
			'instanceId' => $instance_id,
			'searchUrl'  => $instance_url . '/search',
			'itemsUrl'   => $instance_url . '/items',
			'apiToken'   => $api_token,
		];
	}

	/**
	 * @param array{searchUrl:string,apiToken:string} $config
	 * @param array<int, string>                      $visible_pattern_names
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request_search(
		array $config,
		string $query,
		array $visible_pattern_names,
		int $max_results,
		string $error_code,
		string $parse_error_code,
		int $error_status
	): array|\WP_Error {
		$body = self::encode_json(
			self::build_search_request_body( $query, $visible_pattern_names, $max_results )
		);

		$response = self::post_json_with_retry(
			$config['searchUrl'],
			self::json_headers( $config['apiToken'] ),
			$body,
			'Cloudflare Pattern AI Search',
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = $response['status'];
		$data   = $response['data'];

		if ( 200 !== $status ) {
			return self::build_http_error( $error_code, $data, $status, $error_status );
		}

		if ( JSON_ERROR_NONE !== $response['json_error'] || ! is_array( $data ) ) {
			return new \WP_Error(
				$parse_error_code,
				'Failed to parse Cloudflare Pattern AI Search response.',
				[ 'status' => $error_status ]
			);
		}

		return $data;
	}

	/**
	 * @param array<int, string> $visible_pattern_names
	 * @return array<string, mixed>
	 */
	private static function build_search_request_body(
		string $query,
		array $visible_pattern_names,
		int $max_results
	): array {
		return [
			'messages'          => [
				[
					'role'    => 'user',
					'content' => $query,
				],
			],
			'ai_search_options' => [
				'query_rewrite' => [
					'enabled' => false,
				],
				'retrieval'     => [
					'retrieval_type'    => 'hybrid',
					'max_num_results'   => $max_results,
					'match_threshold'   => self::get_match_threshold(),
					'context_expansion' => 0,
					'fusion_method'     => 'rrf',
					'return_on_failure' => true,
					'filters'           => [
						'pattern_name' => [ '$in' => array_values( $visible_pattern_names ) ],
					],
				],
			],
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function json_headers( string $api_token ): array {
		return array_merge(
			self::authorization_headers( $api_token ),
			[
				'Content-Type' => 'application/json',
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function authorization_headers( string $api_token ): array {
		return [
			'Authorization' => 'Bearer ' . $api_token,
		];
	}

	/**
	 * @param array<int, mixed> $chunks
	 * @param array<int, string> $visible_pattern_names
	 * @return array<int, array{name:string,score:float,text:string,metadata:array<string, mixed>,source:string}>
	 */
	private static function normalize_chunks( array $chunks, array $visible_pattern_names ): array {
		$visible = array_fill_keys( $visible_pattern_names, true );
		$results = [];

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$metadata = self::normalize_metadata( $chunk['item']['metadata'] ?? [] );
			$name     = sanitize_text_field( (string) ( $metadata['pattern_name'] ?? '' ) );

			if ( '' === $name || ! isset( $visible[ $name ] ) ) {
				continue;
			}

			$results[] = [
				'name'     => $name,
				'score'    => self::normalize_score( $chunk['score'] ?? 0 ),
				'text'     => sanitize_textarea_field( (string) ( $chunk['text'] ?? '' ) ),
				'metadata' => $metadata,
				'source'   => sanitize_text_field( (string) ( $metadata['source'] ?? ( $chunk['item']['key'] ?? '' ) ) ),
			];
		}

		return $results;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, mixed>
	 */
	private static function extract_chunks( array $data ): array {
		$result = is_array( $data['result'] ?? null ) ? $data['result'] : $data;

		return is_array( $result['chunks'] ?? null ) ? $result['chunks'] : [];
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @return true|\WP_Error
	 */
	private static function is_public_safe_pattern( array $pattern ): true|\WP_Error {
		$status = sanitize_key(
			(string) (
				$pattern['status']
				?? $pattern['post_status']
				?? $pattern['postStatus']
				?? ''
			)
		);

		if ( in_array( $status, [ 'private', 'draft', 'trash', 'trashed' ], true ) ) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_private_pattern',
				'Private, draft, or trashed patterns must not be uploaded to Cloudflare AI Search.',
				[ 'status' => 400 ]
			);
		}

		if (
			array_key_exists( 'public_safe', $pattern )
			&& ! rest_sanitize_boolean( $pattern['public_safe'] )
		) {
			return new \WP_Error(
				'cloudflare_pattern_ai_search_private_pattern',
				'Patterns marked as not public safe must not be uploaded to Cloudflare AI Search.',
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @return array{pattern_name:string,candidate_type:string,source:string,synced_id:string,public_safe:bool}
	 */
	private static function build_metadata( array $pattern, string $item_id ): array {
		return [
			'pattern_name'   => self::pattern_name_from_pattern( $pattern, $item_id ),
			'candidate_type' => self::metadata_text( $pattern['candidate_type'] ?? 'pattern', 'pattern' ),
			'source'         => self::metadata_text( $pattern['source'] ?? 'wordpress', 'wordpress' ),
			'synced_id'      => self::metadata_text( $item_id, $item_id ),
			'public_safe'    => ! array_key_exists( 'public_safe', $pattern )
				|| rest_sanitize_boolean( $pattern['public_safe'] ),
		];
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @param array{pattern_name:string,candidate_type:string,source:string,synced_id:string,public_safe:bool} $metadata
	 */
	private static function build_pattern_markdown( array $pattern, array $metadata ): string {
		$title          = self::pattern_text( $pattern, [ 'title' ], $metadata['pattern_name'] );
		$description    = self::pattern_text( $pattern, [ 'description' ] );
		$categories     = self::pattern_list( $pattern, [ 'categories' ] );
		$block_types    = self::pattern_list( $pattern, [ 'blockTypes', 'block_types' ] );
		$template_types = self::pattern_list( $pattern, [ 'templateTypes', 'template_types' ] );
		$traits         = self::pattern_list( $pattern, [ 'inferredTraits', 'inferred_traits', 'traits' ] );
		$content        = self::sanitize_pattern_content(
			(string) (
				$pattern['content']
				?? $pattern['pattern_content']
				?? $pattern['html']
				?? ''
			)
		);
		$lines          = [
			'# ' . $title,
			'',
		];

		if ( '' !== $description ) {
			$lines[] = '## Description';
			$lines[] = $description;
			$lines[] = '';
		}

		$lines[] = '## Pattern Metadata';
		$lines[] = '- Pattern name: ' . $metadata['pattern_name'];
		$lines[] = '- Candidate type: ' . $metadata['candidate_type'];
		$lines[] = '- Source: ' . $metadata['source'];
		$lines[] = '- Synced ID: ' . $metadata['synced_id'];
		$lines[] = '- Public safe: ' . ( $metadata['public_safe'] ? 'true' : 'false' );
		$lines[] = '- Categories: ' . self::format_list( $categories );
		$lines[] = '- Block types: ' . self::format_list( $block_types );
		$lines[] = '- Template types: ' . self::format_list( $template_types );
		$lines[] = '- Inferred traits: ' . self::format_list( $traits );
		$lines[] = '';
		$lines[] = '## Pattern Content';
		$lines[] = '' !== $content ? $content : '(No pattern content provided.)';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	private static function sanitize_pattern_content( string $content ): string {
		$content = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $content );
		$content = is_string( $content ) ? $content : '';
		$content = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content );
		$content = is_string( $content ) ? $content : '';

		return trim( wp_kses_post( $content ) );
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @param array<int, string>   $keys
	 */
	private static function pattern_text( array $pattern, array $keys, string $fallback = '' ): string {
		foreach ( $keys as $key ) {
			if ( isset( $pattern[ $key ] ) && is_scalar( $pattern[ $key ] ) ) {
				$value = sanitize_text_field( (string) $pattern[ $key ] );

				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return sanitize_text_field( $fallback );
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @param array<int, string>   $keys
	 * @return array<int, string>
	 */
	private static function pattern_list( array $pattern, array $keys ): array {
		foreach ( $keys as $key ) {
			if ( isset( $pattern[ $key ] ) && is_array( $pattern[ $key ] ) ) {
				return self::normalize_string_list( $pattern[ $key ] );
			}
		}

		return [];
	}

	/**
	 * @param array<int, string> $values
	 */
	private static function format_list( array $values ): string {
		return [] !== $values ? implode( ', ', $values ) : 'none';
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function pattern_name_from_pattern( array $pattern, string $fallback ): string {
		foreach ( [ 'pattern_name', 'name' ] as $key ) {
			if ( isset( $pattern[ $key ] ) && is_scalar( $pattern[ $key ] ) ) {
				$value = sanitize_text_field( (string) $pattern[ $key ] );

				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return self::metadata_text( $fallback, $fallback );
	}

	private static function metadata_text( mixed $value, string $fallback ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		if ( '' === $value ) {
			$value = sanitize_text_field( $fallback );
		}

		return substr( $value, 0, 500 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_metadata( mixed $metadata ): array {
		if ( ! is_array( $metadata ) ) {
			return [];
		}

		$normalized = [];

		foreach ( self::SCHEMA_METADATA_FIELD_NAMES as $field_name ) {
			if ( ! array_key_exists( $field_name, $metadata ) ) {
				continue;
			}

			if ( 'public_safe' === $field_name ) {
				$normalized[ $field_name ] = rest_sanitize_boolean( $metadata[ $field_name ] );
			} elseif ( is_scalar( $metadata[ $field_name ] ) ) {
				$normalized[ $field_name ] = sanitize_text_field( (string) $metadata[ $field_name ] );
			}
		}

		return $normalized;
	}

	/**
	 * @param array<int, mixed> $values
	 * @return array<int, string>
	 */
	private static function normalize_string_list( array $values ): array {
		$normalized = [];

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$normalized[ $value ] = $value;
		}

		return array_values( $normalized );
	}

	private static function normalize_config_value( mixed $value ): string {
		return sanitize_text_field( (string) $value );
	}

	private static function normalize_item_id( string $item_id ): string {
		return sanitize_text_field( $item_id );
	}

	private static function filename_for_item_id( string $item_id ): string {
		$filename = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $item_id );
		$filename = is_string( $filename ) ? trim( $filename, '.-' ) : '';

		if ( '' === $filename ) {
			$filename = 'pattern';
		}

		if ( ! str_ends_with( $filename, '.md' ) ) {
			$filename .= '.md';
		}

		return substr( $filename, 0, 128 );
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function append_query_args( string $url, array $args ): string {
		$query = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );

		if ( '' === $query ) {
			return $url;
		}

		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
	}

	private static function normalize_max_results( int $max_results ): int {
		return max( 1, min( self::MAX_MAX_RESULTS, $max_results ) );
	}

	private static function get_match_threshold(): float {
		$threshold = get_option(
			Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
			self::DEFAULT_MATCH_THRESHOLD
		);

		return max( 0.0, min( 1.0, (float) $threshold ) );
	}

	private static function normalize_score( mixed $score ): float {
		if ( ! is_numeric( $score ) ) {
			return 0.0;
		}

		return max( 0.0, min( 1.0, (float) $score ) );
	}

	private static function encode_json( mixed $value ): string {
		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * @param array{status:int,data:mixed,json_error:int} $response
	 */
	private static function assert_successful_json_response(
		array $response,
		string $error_code,
		string $parse_error_code,
		string $label
	): true|\WP_Error {
		$status = $response['status'];
		$data   = $response['data'];

		if ( $status < 200 || $status >= 300 ) {
			return self::build_http_error( $error_code, $data, $status, 502 );
		}

		if (
			$response['body_bytes'] > 0
			&& ( JSON_ERROR_NONE !== $response['json_error'] || ! is_array( $data ) )
		) {
			return new \WP_Error(
				$parse_error_code,
				sprintf( 'Failed to parse %s response.', $label ),
				[ 'status' => 502 ]
			);
		}

		return true;
	}

	private static function build_http_error(
		string $code,
		mixed $data,
		int $http_status,
		int $status
	): \WP_Error {
		return new \WP_Error(
			$code,
			self::extract_error_message( $data, $http_status ),
			[
				'status'             => $status,
				'http_status'        => $http_status,
				'cloudflare_payload' => $data,
			]
		);
	}

	private static function extract_error_message( mixed $data, int $status ): string {
		if ( is_array( $data ) ) {
			$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : [];

			if ( ! empty( $errors[0]['message'] ) && is_string( $errors[0]['message'] ) ) {
				return $errors[0]['message'];
			}

			if ( ! empty( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				return $data['error']['message'];
			}

			if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
		}

		return "Cloudflare Pattern AI Search returned HTTP {$status}.";
	}

	private static function is_schema_filter_error( \WP_Error $error ): bool {
		$text = strtolower( $error->get_error_message() . ' ' . self::encode_json( $error->get_error_data() ) );

		return str_contains( $text, 'pattern_name' )
			&& (
				str_contains( $text, 'filter' )
				|| str_contains( $text, 'metadata' )
				|| str_contains( $text, 'schema' )
				|| str_contains( $text, 'unknown' )
				|| str_contains( $text, 'invalid' )
			);
	}

	private static function build_schema_filter_error( \WP_Error $error ): \WP_Error {
		return new \WP_Error(
			'cloudflare_pattern_ai_search_schema_error',
			sprintf(
				'Cloudflare Pattern AI Search rejected the pattern_name metadata filter. In the Cloudflare AI Search dashboard, add these five custom metadata fields as filterable metadata before syncing patterns: %s. Original error: %s',
				implode( ', ', self::SCHEMA_METADATA_FIELD_NAMES ),
				$error->get_error_message()
			),
			$error->get_error_data()
		);
	}
}
