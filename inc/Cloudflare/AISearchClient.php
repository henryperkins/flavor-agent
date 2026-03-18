<?php

declare(strict_types=1);

namespace FlavorAgent\Cloudflare;

final class AISearchClient {

	private const DEFAULT_MAX_RESULTS = 4;
	private const MAX_MAX_RESULTS = 8;
	private const ALLOWED_DOC_HOSTS = [ 'developer.wordpress.org' ];
	private const ALLOWED_SOURCE_KEY_PREFIX = 'developer.wordpress.org/';

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
				'messages' => [
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

		$result = is_array( $data['result'] ?? null ) ? $data['result'] : [];

		return [
			'query'    => sanitize_text_field( (string) ( $result['search_query'] ?? $query ) ),
			'guidance' => self::normalize_chunks( is_array( $result['chunks'] ?? null ) ? $result['chunks'] : [] ),
		];
	}

	/**
	 * Best-effort search for prompt grounding. Never blocks recommendation flows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function maybe_search( string $query, ?int $max_results = null ): array {
		if ( ! self::is_configured() ) {
			return [];
		}

		$result = self::search( $query, $max_results );

		if ( is_wp_error( $result ) ) {
			return [];
		}

		return is_array( $result['guidance'] ?? null ) ? $result['guidance'] : [];
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
			$url          = self::sanitize_url_value( $metadata['url'] ?? $parsed_chunk['url'] );
			$text         = self::sanitize_excerpt( $parsed_chunk['excerpt'] );

			if ( $text === '' || ! self::is_allowed_guidance_source( $source_key, $url ) ) {
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

	private static function sanitize_url_value( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$sanitized = filter_var( trim( $value ), FILTER_SANITIZE_URL );

		return is_string( $sanitized ) ? $sanitized : '';
	}

	private static function is_allowed_guidance_source( string $source_key, string $url ): bool {
		if ( $source_key === '' && $url === '' ) {
			return false;
		}

		if ( $source_key !== '' && ! self::is_allowed_source_key( $source_key ) ) {
			return false;
		}

		if ( $url !== '' && ! self::is_allowed_guidance_url( $url ) ) {
			return false;
		}

		return true;
	}

	private static function is_allowed_source_key( string $source_key ): bool {
		$normalized = strtolower( trim( $source_key ) );

		if ( $normalized === '' ) {
			return false;
		}

		if ( str_starts_with( $normalized, self::ALLOWED_SOURCE_KEY_PREFIX ) ) {
			return true;
		}

		if ( str_starts_with( $normalized, 'https://' ) || str_starts_with( $normalized, 'http://' ) ) {
			return self::is_allowed_guidance_url( $normalized );
		}

		return false;
	}

	private static function is_allowed_guidance_url( string $url ): bool {
		$host = parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || $host === '' ) {
			return false;
		}

		return in_array( strtolower( $host ), self::ALLOWED_DOC_HOSTS, true );
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
