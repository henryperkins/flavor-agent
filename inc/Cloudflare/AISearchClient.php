<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

use FlavorAgent\Support\DocsGroundingSourcePolicy;

final class AISearchClient {

	private const DEFAULT_MAX_RESULTS       = 4;
	private const MAX_MAX_RESULTS           = 8;
	private const CACHE_KEY_PREFIX          = 'flavor_agent_ai_search_';
	private const CACHE_SCHEMA_VERSION      = 3;
	private const CACHE_TTL                 = 21600;
	private const BEST_EFFORT_TIMEOUT       = 5;
	private const VALIDATION_PROBE_QUERY    = 'block editor';
	private const VALIDATION_PROBE_RESULTS  = 3;
	private const DEFAULT_PUBLIC_SEARCH_URL = 'https://101d836c-480b-4b39-b14e-505a6aa58f47.search.ai.cloudflare.com/search';
	private const PUBLIC_HOST_SUFFIX        = '.search.ai.cloudflare.com';

	public const RUNTIME_STATE_OPTION = 'flavor_agent_docs_runtime_state';

	public static function is_configured(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): bool {
		unset( $account_id, $instance_id, $api_token );

		return ! is_wp_error( self::get_config() );
	}

	public static function configured_instance_id(): ?string {
		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return null;
		}

		return '' !== $config['instanceId'] ? $config['instanceId'] : null;
	}

	/**
	 * Validate that the resolved Cloudflare AI Search backend is queryable.
	 *
	 * Uses a lightweight probe search so documented AI Search Run tokens can pass
	 * validation without requiring instance metadata read access.
	 *
	 * @return array{id: string, source: string, enabled: bool, paused: bool}|\WP_Error
	 */
	public static function validate_configuration(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array|\WP_Error {
		unset( $account_id, $instance_id, $api_token );

		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$guidance = self::validate_trusted_wordpress_docs_source( $config );

		if ( is_wp_error( $guidance ) ) {
			return $guidance;
		}

		return [
			'id'      => $config['instanceId'],
			'source'  => 'public',
			'enabled' => true,
			'paused'  => false,
		];
	}

	/**
	 * Query the resolved Cloudflare AI Search backend for WordPress docs guidance.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function search( string $query, ?int $max_results = null ): array|\WP_Error {
		return self::search_live( $query, $max_results );
	}

	/**
	 * Query the resolved Cloudflare AI Search backend for WordPress docs guidance.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	private static function search_live( string $query, ?int $max_results = null, ?int $timeout = null ): array|\WP_Error {
		$query = sanitize_textarea_field( $query );

		if ( $query === '' ) {
			return new \WP_Error(
				'missing_query',
				'A search query is required.',
				[ 'status' => 400 ]
			);
		}

		$config = self::get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$result_limit = self::normalize_max_results( $max_results );
		$data         = self::request_search(
			$config,
			$query,
			$result_limit,
			'cloudflare_ai_search_error',
			'cloudflare_ai_search_parse_error',
			502,
			$timeout
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$guidance = self::normalize_chunks(
			self::extract_search_chunks( $data ),
			$config['instanceId']
		);

		self::write_cached_guidance( $query, $result_limit, $guidance );

		return [
			'query'    => self::extract_search_query( $data, $query ),
			'guidance' => $guidance,
		];
	}

	/**
	 * Best-effort search for prompt grounding. Never blocks recommendation flows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search( string $query, ?int $max_results = null ): array {
		$query = sanitize_textarea_field( $query );

		if ( $query === '' || ! self::is_configured() ) {
			return [];
		}

		$guidance = self::read_cached_guidance(
			$query,
			self::normalize_max_results( $max_results )
		);

		if ( ! is_array( $guidance ) ) {
			return [];
		}

		return $guidance;
	}

	/**
	 * Best-effort live search for prompt grounding. Reads the result cache first, then
	 * runs a single live search with a short timeout so a hung backend can't stall the
	 * recommendation; transport failures and empty results both resolve to
	 * "no guidance attached." Never blocks a recommendation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search_best_effort( string $query, ?int $max_results = null ): array {
		$query = sanitize_textarea_field( $query );

		if ( $query === '' || ! self::is_configured() ) {
			return [];
		}

		$limit  = self::normalize_max_results( $max_results );
		$cached = self::read_cached_guidance( $query, $limit );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::search_live( $query, $limit, self::BEST_EFFORT_TIMEOUT );

		if ( is_wp_error( $result ) ) {
			self::write_runtime_signal( 'unreachable', 0 );

			return [];
		}

		self::write_runtime_signal( 'ok', count( $result['guidance'] ) );

		return $result['guidance'];
	}

	private static function write_runtime_signal( string $status, int $count ): void {
		update_option(
			self::RUNTIME_STATE_OPTION,
			[
				'status'          => $status,
				'lastSearchAt'    => gmdate( 'Y-m-d H:i:s' ),
				'lastResultCount' => $count,
			],
			false
		);
	}

	/**
	 * Minimal runtime signal for operators: the docs backend is reachable, was
	 * unreachable on the last live search, or grounding is off (unconfigured).
	 *
	 * @return array{status: string, lastSearchAt: string, lastResultCount: int}
	 */
	public static function get_runtime_state(): array {
		if ( ! self::is_configured() ) {
			return [
				'status'          => 'off',
				'lastSearchAt'    => '',
				'lastResultCount' => 0,
			];
		}

		$state = get_option( self::RUNTIME_STATE_OPTION, [] );

		if ( ! is_array( $state ) ) {
			$state = [];
		}

		return [
			'status'          => in_array( (string) ( $state['status'] ?? '' ), [ 'ok', 'unreachable' ], true )
				? (string) $state['status']
				: 'ok',
			'lastSearchAt'    => sanitize_text_field( (string) ( $state['lastSearchAt'] ?? '' ) ),
			'lastResultCount' => (int) ( $state['lastResultCount'] ?? 0 ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function build_config_identity_payload(
		bool $include_secret = false,
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array {
		unset( $account_id, $instance_id, $api_token );

		$config = self::get_config();

		if ( is_wp_error( $config ) ) {
			return [];
		}

		$payload = [
			'mode'               => $config['mode'],
			'instanceId'         => $config['instanceId'],
			'searchUrl'          => $config['searchUrl'],
			'cacheSchemaVersion' => (string) self::CACHE_SCHEMA_VERSION,
		];

		if ( $include_secret && $config['apiToken'] !== '' ) {
			$payload['apiToken'] = $config['apiToken'];
		}

		return $payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_config(
		?string $account_id = null,
		?string $instance_id = null,
		?string $api_token = null
	): array|\WP_Error {
		unset( $account_id, $instance_id, $api_token );

		return self::build_public_config( self::get_public_search_url() );
	}

	/**
	 * @return array{mode: string, namespace: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string}|\WP_Error
	 */
	private static function build_public_config( string $search_url ): array|\WP_Error {
		$normalized_search_url = self::normalize_public_search_url( $search_url );

		if ( '' === $normalized_search_url ) {
			return new \WP_Error(
				'invalid_cloudflare_ai_search_public_endpoint',
				'Cloudflare AI Search public search URL is invalid.',
				[ 'status' => 400 ]
			);
		}

		return [
			'mode'        => 'public',
			'namespace'   => '',
			'instanceId'  => self::extract_public_instance_id( $normalized_search_url ),
			'instanceUrl' => '',
			'searchUrl'   => $normalized_search_url,
			'apiToken'    => '',
		];
	}

	private static function get_public_search_url(): string {
		return self::normalize_public_search_url( self::DEFAULT_PUBLIC_SEARCH_URL );
	}

	private static function normalize_public_search_url( string $search_url ): string {
		$search_url = trim( $search_url );

		if ( '' === $search_url ) {
			return '';
		}

		$parts = wp_parse_url( $search_url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if (
			'https' !== $scheme ||
			'' === $host ||
			! str_ends_with( $host, self::PUBLIC_HOST_SUFFIX ) ||
			( isset( $parts['user'] ) && '' !== (string) $parts['user'] ) ||
			( isset( $parts['pass'] ) && '' !== (string) $parts['pass'] ) ||
			( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ||
			isset( $parts['query'] ) ||
			isset( $parts['fragment'] )
		) {
			return '';
		}

		$path = preg_replace( '#/+#', '/', $path );
		$path = is_string( $path ) ? rtrim( $path, '/' ) : '';

		if ( '' === $path ) {
			$path = '/search';
		} elseif ( '/mcp' === $path ) {
			$path = '/search';
		}

		if ( '/search' !== $path ) {
			return '';
		}

		return 'https://' . $host . $path;
	}

	private static function extract_public_instance_id( string $search_url ): string {
		$parts = wp_parse_url( $search_url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( ! str_ends_with( $host, self::PUBLIC_HOST_SUFFIX ) ) {
			return '';
		}

		$instance_id = substr( $host, 0, -strlen( self::PUBLIC_HOST_SUFFIX ) );

		if ( ! is_string( $instance_id ) || '' === $instance_id || str_contains( $instance_id, '.' ) ) {
			return '';
		}

		return strtolower( sanitize_text_field( $instance_id ) );
	}

	private static function normalize_max_results( ?int $max_results ): int {
		if ( null === $max_results ) {
			$max_results = (int) get_option(
				'flavor_agent_cloudflare_ai_search_max_results',
				self::DEFAULT_MAX_RESULTS
			);
		}

		return max( 1, min( self::MAX_MAX_RESULTS, (int) $max_results ) );
	}

	/**
	 * @param array{mode: string, namespace: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string} $config
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request_search(
		array $config,
		string $query,
		int $result_limit,
		string $error_code,
		string $parse_error_code,
		int $error_status,
		?int $timeout = null
	): array|\WP_Error {
		$headers = [
			'Content-Type' => 'application/json',
		];

		if ( $config['apiToken'] !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $config['apiToken'];
		}

		$response = wp_remote_post(
			$config['searchUrl'],
			[
				'timeout' => null !== $timeout ? max( 1, $timeout ) : 20,
				'headers' => $headers,
				'body'    => self::build_search_request_body( $query, $result_limit ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status !== 200 ) {
			$message = is_array( $data ) ? self::extract_error_message( $data, $status ) : "Cloudflare AI Search returned HTTP {$status}";

			return new \WP_Error(
				$error_code,
				$message,
				[ 'status' => $error_status ]
			);
		}

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error(
				$parse_error_code,
				'Failed to parse Cloudflare AI Search response.',
				[ 'status' => $error_status ]
			);
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, mixed>
	 */
	private static function extract_search_chunks( array $data ): array {
		$result = is_array( $data['result'] ?? null ) ? $data['result'] : $data;

		return is_array( $result['chunks'] ?? null ) ? $result['chunks'] : [];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function extract_search_query( array $data, string $fallback ): string {
		$result = is_array( $data['result'] ?? null ) ? $data['result'] : $data;

		return sanitize_text_field( (string) ( $result['search_query'] ?? $fallback ) );
	}

	private static function build_search_request_body( string $query, int $result_limit ): string {
		$body = wp_json_encode(
			[
				'messages'          => [
					[
						'role'    => 'user',
						'content' => $query,
					],
				],
				'ai_search_options' => [
					'retrieval' => [
						'retrieval_type'    => 'hybrid',
						'max_num_results'   => $result_limit,
						'match_threshold'   => 0.2,
						'context_expansion' => 1,
						'fusion_method'     => 'rrf',
						'return_on_failure' => true,
					],
				],
			]
		);

		return is_string( $body ) ? $body : '';
	}

	private static function build_cache_namespace(): string {
		$payload = wp_json_encode( self::build_config_identity_payload() );

		if ( ! is_string( $payload ) || $payload === '' ) {
			return '';
		}

		return md5( $payload );
	}

	private static function build_cache_key( string $query, int $max_results ): string {
		$payload = wp_json_encode(
			[
				'namespace'  => self::build_cache_namespace(),
				'query'      => $query,
				'maxResults' => $max_results,
			]
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			$payload = "{$query}|{$max_results}";
		}

		return self::CACHE_KEY_PREFIX . md5( $payload );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function validate_trusted_wordpress_docs_source( array $config ): array|\WP_Error {
		$data = self::request_search(
			$config,
			self::VALIDATION_PROBE_QUERY,
			self::VALIDATION_PROBE_RESULTS,
			'cloudflare_ai_search_validation_error',
			'cloudflare_ai_search_validation_parse_error',
			400
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$guidance = self::normalize_chunks(
			self::extract_search_chunks( $data ),
			$config['instanceId']
		);

		// Settings-side sanity probe only: the built-in endpoint must actually
		// serve WordPress developer docs. Recommendation flows never gate on this.
		$has_wordpress_docs = false;

		foreach ( $guidance as $chunk ) {
			$host = wp_parse_url( (string) ( $chunk['url'] ?? '' ), PHP_URL_HOST );

			if (
				is_string( $host ) &&
				in_array( strtolower( $host ), [ 'developer.wordpress.org', 'make.wordpress.org' ], true )
			) {
				$has_wordpress_docs = true;
				break;
			}
		}

		if ( ! $has_wordpress_docs ) {
			return new \WP_Error(
				'cloudflare_ai_search_validation_untrusted_source',
				'Cloudflare AI Search validation could not confirm developer.wordpress.org content from the built-in public Developer Docs endpoint.',
				[ 'status' => 400 ]
			);
		}

		return $guidance;
	}

	/**
	 * @param array{mode: string, instanceId: string, instanceUrl: string, searchUrl: string, apiToken: string} $config
	 */
	private static function read_cached_guidance( string $query, int $max_results ): ?array {
		return self::read_cached_guidance_by_key(
			self::build_cache_key( $query, $max_results ),
			self::CACHE_TTL
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function write_cached_guidance( string $query, int $max_results, array $guidance ): void {
		self::write_cached_guidance_by_key(
			self::build_cache_key( $query, $max_results ),
			$guidance,
			self::CACHE_TTL
		);
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function read_cached_guidance_by_key( string $cache_key, int $ttl ): ?array {
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			return null;
		}

		if ( ! is_array( $cached ) ) {
			delete_transient( $cache_key );

			return null;
		}

		$guidance = self::normalize_cached_guidance( $cached );

		if ( [] === $guidance && [] !== $cached ) {
			delete_transient( $cache_key );

			return null;
		}

		if ( $guidance !== $cached ) {
			set_transient( $cache_key, $guidance, $ttl );
		}

		return $guidance;
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function write_cached_guidance_by_key( string $cache_key, array $guidance, int $ttl ): void {
		set_transient(
			$cache_key,
			self::normalize_cached_guidance( $guidance ),
			$ttl
		);
	}

	/**
	 * @param array<int, mixed> $guidance
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_cached_guidance( array $guidance ): array {
		$normalized = [];

		foreach ( $guidance as $item ) {
			$normalized_item = self::normalize_cached_guidance_item( $item );

			if ( null === $normalized_item ) {
				continue;
			}

			$normalized[] = $normalized_item;
		}

		return $normalized;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function normalize_cached_guidance_item( mixed $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$url     = self::normalize_guidance_url_candidate( $item['url'] ?? null );
		$excerpt = self::sanitize_excerpt( (string) ( $item['excerpt'] ?? '' ) );

		if ( $url === '' || $excerpt === '' ) {
			return null;
		}

		return [
			'id'          => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'sourceKey'   => sanitize_text_field( (string) ( $item['sourceKey'] ?? '' ) ),
			'sourceType'  => DocsGroundingSourcePolicy::label_for_url( $url ),
			'url'         => $url,
			'excerpt'     => $excerpt,
			'score'       => isset( $item['score'] ) ? max( 0.0, min( 1.0, (float) $item['score'] ) ) : 0.0,
			'contentHash' => sanitize_text_field( (string) ( $item['contentHash'] ?? '' ) ),
		];
	}

	/**
	 * @param array<int, mixed> $chunks Raw chunk list from Cloudflare AI Search.
	 * @param string|null       $instance_id Resolved Cloudflare AI Search instance ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_chunks( array $chunks, ?string $instance_id = null ): array {
		$guidance = [];

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$item          = is_array( $chunk['item'] ?? null ) ? $chunk['item'] : [];
			$item_metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : [];
			$parsed_chunk  = self::parse_chunk_text( (string) ( $chunk['text'] ?? '' ) );
			$source_key    = sanitize_text_field( (string) ( $item['key'] ?? '' ) );
			$url           = self::normalize_guidance_url(
				self::collect_guidance_url_candidates(
					$item_metadata,
					$parsed_chunk['url'],
					$source_key,
					$instance_id
				)
			);
			$text          = self::sanitize_excerpt( $parsed_chunk['excerpt'] );

			if ( $text === '' || $url === '' ) {
				continue;
			}

			$metadata = is_array( $parsed_chunk['metadata'] ?? null ) ? $parsed_chunk['metadata'] : [];

			$guidance[] = [
				'id'          => sanitize_text_field( (string) ( $chunk['id'] ?? '' ) ),
				'title'       => sanitize_text_field( (string) ( $item_metadata['title'] ?? '' ) ),
				'sourceKey'   => $source_key,
				'sourceType'  => DocsGroundingSourcePolicy::label_for_url( $url ),
				'url'         => $url,
				'excerpt'     => $text,
				'score'       => isset( $chunk['score'] ) ? max( 0.0, min( 1.0, (float) $chunk['score'] ) ) : 0.0,
				'contentHash' => sanitize_text_field( (string) ( $metadata['content_hash'] ?? '' ) ),
			];
		}

		return $guidance;
	}

	private static function sanitize_excerpt( string $text ): string {
		return \FlavorAgent\Support\GuidanceExcerpt::sanitize( $text );
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<int, string>
	 */
	private static function collect_guidance_url_candidates( array $metadata, string $frontmatter_url, string $source_key, ?string $instance_id = null ): array {
		$candidates = [];

		foreach ( [ 'url', 'source_url', 'sourceUrl', 'original_url', 'originalUrl', 'permalink' ] as $key ) {
			if ( isset( $metadata[ $key ] ) && is_string( $metadata[ $key ] ) && '' !== trim( $metadata[ $key ] ) ) {
				$candidates[] = $metadata[ $key ];
			}
		}

		if ( '' !== trim( $frontmatter_url ) ) {
			$candidates[] = $frontmatter_url;
		}

		$source_key_url = self::normalize_guidance_url_from_source_key( $source_key, $instance_id );

		if ( '' !== $source_key_url ) {
			$candidates[] = $source_key_url;
		}

		return $candidates;
	}

	/**
	 * First structurally usable https URL wins; candidates are ordered
	 * metadata, frontmatter, then source-key derivation.
	 *
	 * @param array<int, string> $url_candidates
	 */
	private static function normalize_guidance_url( array $url_candidates ): string {
		foreach ( $url_candidates as $url_candidate ) {
			$normalized_candidate = self::normalize_guidance_url_candidate( $url_candidate );

			if ( '' !== $normalized_candidate ) {
				return $normalized_candidate;
			}
		}

		return '';
	}

	/**
	 * Structural URL hygiene only — https, a real host, no embedded credentials,
	 * no traversal segments. Host allowlisting is the ingestion script's job.
	 */
	private static function normalize_guidance_url_candidate( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$parts = wp_parse_url( trim( $value ) );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if (
			'https' !== $scheme ||
			'' === $host ||
			'' === $path ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ||
			self::path_contains_untrusted_segments( $path )
		) {
			return '';
		}

		$normalized_path = preg_replace( '#/+#', '/', $path );

		if ( ! is_string( $normalized_path ) || '' === $normalized_path ) {
			return '';
		}

		return 'https://' . $host . '/' . ltrim( $normalized_path, '/' );
	}

	private static function normalize_guidance_url_from_source_key( string $source_key, ?string $instance_id = null ): string {
		$source_key = trim( $source_key );
		$direct_url = self::normalize_guidance_url_candidate( $source_key );

		if ( '' !== $direct_url ) {
			return $direct_url;
		}

		$normalized = strtolower( $source_key );

		if ( $normalized === '' ) {
			return '';
		}

		$source_key_url = self::parse_source_key_url( $normalized, $instance_id );

		if ( '' === $source_key_url ) {
			return '';
		}

		$parts = wp_parse_url( $source_key_url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		$path  = is_array( $parts ) ? trim( (string) ( $parts['path'] ?? '' ), '/' ) : '';

		if (
			$path === '' ||
			str_contains( $path, '?' ) ||
			str_contains( $path, '#' ) ||
			str_contains( $path, '\\' ) ||
			self::path_contains_untrusted_segments( $path )
		) {
			return '';
		}

		$segments = array_values(
			array_filter(
				explode( '/', $path ),
				static fn ( string $segment ): bool => $segment !== ''
			)
		);

		if ( [] === $segments ) {
			return '';
		}

		$last_segment = $segments[ count( $segments ) - 1 ];

		if ( preg_match( '/^(?:part-\d+|index)\.md$/', $last_segment ) ) {
			array_pop( $segments );
			$last_segment = $segments[ count( $segments ) - 1 ] ?? '';
		}

		if ( is_string( $last_segment ) && preg_match( '/^[a-f0-9]{32,}$/', $last_segment ) ) {
			array_pop( $segments );
		}

		if ( [] === $segments ) {
			return '';
		}

		return self::normalize_guidance_url_candidate(
			'https://' . $host . '/' . implode( '/', $segments ) . '/'
		);
	}

	private static function normalize_source_key_instance_id( ?string $instance_id = null ): string {
		$resolved = trim(
			(string) (
				null !== $instance_id
					? $instance_id
					: self::extract_public_instance_id( self::get_public_search_url() )
			)
		);

		if ( $resolved === '' || str_contains( $resolved, '/' ) || str_contains( $resolved, '\\' ) ) {
			return '';
		}

		return strtolower( sanitize_text_field( $resolved ) );
	}

	private static function parse_source_key_url( string $source_key, ?string $instance_id = null ): string {
		$hosts               = strtolower( 'developer\.WordPress\.org|make\.WordPress\.org' );
		$normalized_instance = self::normalize_source_key_instance_id( $instance_id );
		$instance_pattern    = 'wp-dev-docs';

		if ( '' !== $normalized_instance ) {
			$instance_pattern .= '|' . preg_quote( $normalized_instance, '/' );
		}

		if ( preg_match( '/^(' . $hosts . ')\/(.+)$/', $source_key, $matches ) ) {
			return self::normalize_guidance_url_candidate(
				'https://' . (string) $matches[1] . '/' . (string) $matches[2]
			);
		}

		if ( preg_match( '/^ai-search\/(?:' . $instance_pattern . ')\/(' . $hosts . ')\/(.+)$/', $source_key, $matches ) ) {
			return self::normalize_guidance_url_candidate(
				'https://' . (string) $matches[1] . '/' . (string) $matches[2]
			);
		}

		return '';
	}

	private static function path_contains_untrusted_segments( string $path ): bool {
		$segments = array_values(
			array_filter(
				explode( '/', trim( $path, '/' ) ),
				static fn ( string $segment ): bool => $segment !== ''
			)
		);

		foreach ( $segments as $segment ) {
			$decoded = rawurldecode( $segment );

			if (
				$decoded === '.' ||
				$decoded === '..' ||
				str_contains( $decoded, '/' ) ||
				str_contains( $decoded, '\\' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{excerpt: string, url: string, metadata: array<string, string>}
	 */
	private static function parse_chunk_text( string $text ): array {
		$normalized_text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$url             = '';
		$excerpt         = $normalized_text;
		$metadata        = [];

		if ( preg_match( '/\A---\n(.*?)\n---(?:\n|$)(.*)\z/s', $normalized_text, $matches ) ) {
			$frontmatter = (string) ( $matches[1] ?? '' );
			$excerpt     = (string) ( $matches[2] ?? '' );
			$metadata    = self::parse_chunk_frontmatter( $frontmatter );
			$url         = $metadata['original_url'] ?? ( $metadata['source_url'] ?? '' );
		}

		return [
			'excerpt'  => $excerpt,
			'url'      => $url,
			'metadata' => $metadata,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function parse_chunk_frontmatter( string $frontmatter ): array {
		$metadata = [];

		foreach ( explode( "\n", $frontmatter ) as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9_-]+):\s*(?:"([^"]*)"|([^#]+))/', trim( $line ), $matches ) ) {
				continue;
			}

			$key   = sanitize_key( (string) $matches[1] );
			$value = trim( (string) ( ( $matches[2] ?? '' ) !== '' ? $matches[2] : ( $matches[3] ?? '' ) ) );

			if ( '' !== $key && '' !== $value ) {
				$metadata[ $key ] = sanitize_text_field( $value );
			}
		}

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function extract_error_message( array $data, int $status ): string {
		$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : [];

		if ( ! empty( $errors[0]['message'] ) && is_string( $errors[0]['message'] ) ) {
			return $errors[0]['message'];
		}

		if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}

		return "Cloudflare AI Search returned HTTP {$status}";
	}
}
