<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

final class AISearchClient {

	private const DEFAULT_MAX_RESULTS       = 4;
	private const MAX_MAX_RESULTS           = 8;
	private const ALLOWED_DOC_HOST          = 'developer.wordpress.org';
	private const ALLOWED_SOURCE_KEY_PREFIX = 'developer.wordpress.org/';
	private const CACHE_KEY_PREFIX          = 'flavor_agent_ai_search_';
	private const CACHE_TTL                 = 21600;
	private const ENTITY_CACHE_PREFIX       = 'flavor_agent_docs_entity_';
	private const ENTITY_CACHE_TTL          = 43200;

	public static function is_configured(): bool {
		$account_id  = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_account_id', '' ) );
		$instance_id = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' ) );
		$api_token   = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_api_token', '' ) );

		return $account_id !== '' && $instance_id !== '' && $api_token !== '';
	}

	/**
	 * Query the configured Cloudflare AI Search instance for WordPress docs guidance.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function search( string $query, ?int $max_results = null ): array|\WP_Error {
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
		$body         = wp_json_encode(
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

		$response = wp_remote_post(
			$config['url'],
			[
				'timeout' => 20,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $config['apiToken'],
				],
				'body'    => $body,
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
				'cloudflare_ai_search_error',
				$message,
				[ 'status' => 502 ]
			);
		}

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error(
				'cloudflare_ai_search_parse_error',
				'Failed to parse Cloudflare AI Search response.',
				[ 'status' => 502 ]
			);
		}

		$result   = is_array( $data['result'] ?? null ) ? $data['result'] : [];
		$guidance = self::normalize_chunks( is_array( $result['chunks'] ?? null ) ? $result['chunks'] : [] );

		self::write_cached_guidance( $query, $result_limit, $guidance );

		return [
			'query'    => sanitize_text_field( (string) ( $result['search_query'] ?? $query ) ),
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
	 * Best-effort cache lookup for prompt grounding.
	 * Exact-query cache remains authoritative; entity cache is only a fallback.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search_with_entity_fallback( string $query, string $entity_key = '', ?int $max_results = null ): array {
		$guidance = self::maybe_search( $query, $max_results );

		if ( [] !== $guidance ) {
			return $guidance;
		}

		return self::maybe_search_entity( $entity_key );
	}

	/**
	 * Best-effort entity lookup for prompt grounding. Never blocks recommendation flows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search_entity( string $entity_key ): array {
		$entity_key = self::normalize_entity_key( $entity_key );

		if ( $entity_key === '' || ! self::is_configured() ) {
			return [];
		}

		$guidance = self::read_cached_guidance_by_key(
			self::build_entity_cache_key( $entity_key ),
			self::ENTITY_CACHE_TTL
		);

		if ( ! is_array( $guidance ) ) {
			return [];
		}

		return $guidance;
	}

	/**
	 * Perform an explicit search and seed the shared entity cache when possible.
	 *
	 * @return array{query: string, guidance: array<int, array<string, mixed>>}|\WP_Error
	 */
	public static function warm_entity( string $entity_key, string $query, ?int $max_results = null ): array|\WP_Error {
		$result = self::search( $query, $max_results );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::cache_entity_guidance( $entity_key, $result['guidance'] );

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	public static function cache_entity_guidance( string $entity_key, array $guidance ): void {
		$entity_key = self::normalize_entity_key( $entity_key );

		if ( $entity_key === '' ) {
			return;
		}

		self::write_cached_guidance_by_key(
			self::build_entity_cache_key( $entity_key ),
			$guidance,
			self::ENTITY_CACHE_TTL
		);
	}

	public static function resolve_entity_key( string $entity_key = '', string $query = '' ): string {
		$entity_key = self::normalize_entity_key( $entity_key );

		if ( $entity_key !== '' ) {
			return $entity_key;
		}

		return self::infer_entity_key_from_query( $query );
	}

	public static function infer_entity_key_from_query( string $query ): string {
		$query = sanitize_textarea_field( $query );

		if ( $query === '' ) {
			return '';
		}

		if ( preg_match( '/\bblock type ([a-z0-9-]+\/[a-z0-9-]+)\b/i', $query, $matches ) ) {
			return self::normalize_entity_key( (string) ( $matches[1] ?? '' ) );
		}

		if ( preg_match( '/\btemplate type ([a-z0-9_-]+)\b/i', $query, $matches ) ) {
			return self::normalize_entity_key( 'template:' . (string) ( $matches[1] ?? '' ) );
		}

		if (
			preg_match( '/\b(block|gutenberg)\b/i', $query )
			&& preg_match( '/\b([a-z0-9-]+\/[a-z0-9-]+)\b/i', $query, $matches )
		) {
			return self::normalize_entity_key( (string) ( $matches[1] ?? '' ) );
		}

		return '';
	}

	/**
	 * @return array{url: string, apiToken: string}|\WP_Error
	 */
	private static function get_config(): array|\WP_Error {
		$account_id  = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_account_id', '' ) );
		$instance_id = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' ) );
		$api_token   = trim( (string) get_option( 'flavor_agent_cloudflare_ai_search_api_token', '' ) );

		if ( $account_id === '' || $instance_id === '' || $api_token === '' ) {
			return new \WP_Error(
				'missing_cloudflare_ai_search_credentials',
				'Cloudflare AI Search credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		return [
			'url'      => sprintf(
				'https://api.cloudflare.com/client/v4/accounts/%s/ai-search/instances/%s/search',
				rawurlencode( $account_id ),
				rawurlencode( $instance_id )
			),
			'apiToken' => $api_token,
		];
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

	private static function build_cache_key( string $query, int $max_results ): string {
		$payload = wp_json_encode(
			[
				'query'      => $query,
				'maxResults' => $max_results,
			]
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			$payload = "{$query}|{$max_results}";
		}

		return self::CACHE_KEY_PREFIX . md5( $payload );
	}

	private static function build_entity_cache_key( string $entity_key ): string {
		return self::ENTITY_CACHE_PREFIX . md5( $entity_key );
	}

	private static function normalize_entity_key( string $entity_key ): string {
		$entity_key = strtolower( trim( sanitize_text_field( $entity_key ) ) );

		if ( $entity_key === '' ) {
			return '';
		}

		if ( str_starts_with( $entity_key, 'template:' ) ) {
			$template_type = sanitize_key( substr( $entity_key, strlen( 'template:' ) ) );

			return $template_type !== '' ? 'template:' . $template_type : '';
		}

		return preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $entity_key ) === 1 ? $entity_key : '';
	}

	/**
	 * @return array<int, array<string, mixed>>|null
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

		$source_key = sanitize_text_field( (string) ( $item['sourceKey'] ?? '' ) );
		$url        = self::normalize_trusted_guidance_url( $item['url'] ?? null );
		$excerpt    = self::sanitize_excerpt( (string) ( $item['excerpt'] ?? '' ) );

		if ( $url === '' || $excerpt === '' || ! self::is_allowed_guidance_source( $source_key, $url ) ) {
			return null;
		}

		return [
			'id'        => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
			'title'     => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'sourceKey' => $source_key,
			'url'       => $url,
			'excerpt'   => $excerpt,
			'score'     => isset( $item['score'] ) ? max( 0.0, min( 1.0, (float) $item['score'] ) ) : 0.0,
		];
	}

	/**
	 * @param array<int, mixed> $chunks Raw chunk list from Cloudflare AI Search.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_chunks( array $chunks ): array {
		$guidance = [];

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$item         = is_array( $chunk['item'] ?? null ) ? $chunk['item'] : [];
			$metadata     = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : [];
			$parsed_chunk = self::parse_chunk_text( (string) ( $chunk['text'] ?? '' ) );
			$source_key   = sanitize_text_field( (string) ( $item['key'] ?? '' ) );
			$url          = self::normalize_guidance_url( $metadata['url'] ?? null, $parsed_chunk['url'] );
			$text         = self::sanitize_excerpt( $parsed_chunk['excerpt'] );

			if ( $text === '' || $url === '' || ! self::is_allowed_guidance_source( $source_key, $url ) ) {
				continue;
			}

			$guidance[] = [
				'id'        => sanitize_text_field( (string) ( $chunk['id'] ?? '' ) ),
				'title'     => sanitize_text_field( (string) ( $metadata['title'] ?? '' ) ),
				'sourceKey' => $source_key,
				'url'       => $url,
				'excerpt'   => $text,
				'score'     => isset( $chunk['score'] ) ? max( 0.0, min( 1.0, (float) $chunk['score'] ) ) : 0.0,
			];
		}

		return $guidance;
	}

	private static function sanitize_excerpt( string $text ): string {
		$text = sanitize_textarea_field( $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		if ( strlen( $text ) > 360 ) {
			$text = substr( $text, 0, 357 ) . '...';
		}

		return $text;
	}

	private static function normalize_guidance_url( mixed $metadata_url, string $frontmatter_url ): string {
		$has_metadata_url    = is_string( $metadata_url ) && trim( $metadata_url ) !== '';
		$has_frontmatter_url = trim( $frontmatter_url ) !== '';

		$normalized_metadata_url    = self::normalize_trusted_guidance_url( $metadata_url );
		$normalized_frontmatter_url = self::normalize_trusted_guidance_url( $frontmatter_url );

		if ( $has_metadata_url && $normalized_metadata_url === '' ) {
			return '';
		}

		if ( $has_frontmatter_url && $normalized_frontmatter_url === '' ) {
			return '';
		}

		if (
			$normalized_metadata_url !== '' &&
			$normalized_frontmatter_url !== '' &&
			! self::guidance_urls_match( $normalized_metadata_url, $normalized_frontmatter_url )
		) {
			return '';
		}

		if ( $normalized_metadata_url !== '' ) {
			return $normalized_metadata_url;
		}

		return $normalized_frontmatter_url;
	}

	private static function normalize_trusted_guidance_url( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url = trim( $value );

		if ( $url === '' ) {
			return '';
		}

		$parts = parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = is_string( $parts['scheme'] ?? null ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_string( $parts['host'] ?? null ) ? strtolower( $parts['host'] ) : '';
		$path   = $parts['path'] ?? null;

		if (
			$scheme !== 'https' ||
			$host !== self::ALLOWED_DOC_HOST ||
			( isset( $parts['user'] ) && $parts['user'] !== '' ) ||
			( isset( $parts['pass'] ) && $parts['pass'] !== '' ) ||
			( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) ||
			! is_string( $path ) ||
			$path === ''
		) {
			return '';
		}

		$normalized_path = preg_replace( '#/+#', '/', $path );

		if ( ! is_string( $normalized_path ) || $normalized_path === '' ) {
			return '';
		}

		return 'https://' . self::ALLOWED_DOC_HOST . '/' . ltrim( $normalized_path, '/' );
	}

	private static function is_allowed_guidance_source( string $source_key, string $url ): bool {
		$url_identity = self::normalize_guidance_identity( $url );

		if ( $url_identity === '' ) {
			return false;
		}

		if ( $source_key === '' ) {
			return true;
		}

		return self::normalize_source_key_identity( $source_key ) === $url_identity;
	}

	private static function normalize_source_key_identity( string $source_key ): string {
		$normalized = strtolower( trim( $source_key ) );

		if ( $normalized === '' ) {
			return '';
		}

		if ( str_starts_with( $normalized, self::ALLOWED_SOURCE_KEY_PREFIX ) ) {
			$normalized = 'https://' . $normalized;
		}

		return self::normalize_guidance_identity( $normalized );
	}

	private static function normalize_guidance_identity( string $url ): string {
		$normalized = self::normalize_trusted_guidance_url( $url );

		if ( $normalized === '' ) {
			return '';
		}

		$path = parse_url( $normalized, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return '';
		}

		$normalized_path = rtrim( $path, '/' );

		if ( $normalized_path === '' ) {
			$normalized_path = '/';
		}

		return 'https://' . self::ALLOWED_DOC_HOST . $normalized_path;
	}

	private static function guidance_urls_match( string $left, string $right ): bool {
		return self::normalize_guidance_identity( $left ) === self::normalize_guidance_identity( $right );
	}

	/**
	 * @return array{excerpt: string, url: string}
	 */
	private static function parse_chunk_text( string $text ): array {
		$url     = '';
		$excerpt = $text;

		if ( str_starts_with( $text, "---\n" ) ) {
			$parts = explode( "\n---\n", $text, 2 );

			if ( count( $parts ) === 2 ) {
				$frontmatter = $parts[0];
				$excerpt     = $parts[1];

				if ( preg_match( '/(?:original_url|source_url):\s*"([^"]+)"/', $frontmatter, $matches ) ) {
					$url = (string) ( $matches[1] ?? '' );
				}
			}
		}

		return [
			'excerpt' => $excerpt,
			'url'     => $url,
		];
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
