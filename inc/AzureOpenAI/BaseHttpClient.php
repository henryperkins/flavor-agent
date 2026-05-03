<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

abstract class BaseHttpClient {

	private const MAX_RETRY_AFTER_SECONDS = 60;

	/**
	 * @param array<string, string> $headers
	 * @return array{
	 *   status: int,
	 *   data: mixed,
	 *   json_error: int,
	 *   headers: array<string, string>,
	 *   body_bytes: int
	 * }|\WP_Error
	 */
	protected static function post_json_with_retry(
		string $url,
		array $headers,
		string $body,
		string $label,
		int $timeout
	): array|\WP_Error {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return ConfigurationValidator::normalize_transport_error(
				$response,
				$label,
				$url,
				$timeout
			);
		}

		return self::normalize_json_response( $response, $label );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{
	 *   status: int,
	 *   data: mixed,
	 *   json_error: int,
	 *   headers: array<string, string>,
	 *   body_bytes: int
	 * }|\WP_Error
	 */
	protected static function request_with_retry(
		string $url,
		array $args,
		string $label,
		int $timeout
	): array|\WP_Error {
		$args['timeout'] = $timeout;
		$response        = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return ConfigurationValidator::normalize_transport_error(
				$response,
				$label,
				$url,
				$timeout
			);
		}

		return self::normalize_json_response( $response, $label );
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, string> $fields
	 * @param array{name:string,filename:string,contents:string,content_type:string} $file
	 * @return array{
	 *   status: int,
	 *   data: mixed,
	 *   json_error: int,
	 *   headers: array<string, string>,
	 *   body_bytes: int
	 * }|\WP_Error
	 */
	protected static function post_multipart_with_retry(
		string $url,
		array $headers,
		array $fields,
		array $file,
		string $label,
		int $timeout
	): array|\WP_Error {
		$boundary                = 'flavor-agent-' . md5( $url . '|' . microtime( true ) );
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		return self::request_with_retry(
			$url,
			[
				'method'  => 'POST',
				'headers' => $headers,
				'body'    => self::build_multipart_body( $boundary, $fields, $file ),
			],
			$label,
			$timeout
		);
	}

	public static function parse_retry_after_header( string|false $header, ?int $now = null ): ?int {
		if ( false === $header ) {
			return null;
		}

		$header = trim( $header );

		if ( '' === $header ) {
			return null;
		}

		if ( preg_match( '/^\d+$/', $header ) ) {
			return max( 1, min( self::MAX_RETRY_AFTER_SECONDS, (int) $header ) );
		}

		$retry_at = strtotime( $header );

		if ( false === $retry_at ) {
			return null;
		}

		$delay = $retry_at - ( $now ?? time() );

		return max( 1, min( self::MAX_RETRY_AFTER_SECONDS, $delay ) );
	}

	public static function build_retryable_rate_limit_error(
		string $code,
		string $message,
		string|false $header,
		array $extra_data = []
	): \WP_Error {
		$retry_after = self::parse_retry_after_header( $header );

		return new \WP_Error(
			$code,
			$message,
			array_merge(
				[
					'status'          => 429,
					'retryable'       => true,
					'retry_after'     => $retry_after,
					'retry_after_raw' => false !== $header ? trim( $header ) : null,
				],
				$extra_data
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected static function extract_response_headers( $response ): array {
		if ( ! is_array( $response ) || ! is_array( $response['headers'] ?? null ) ) {
			return [];
		}

		$headers = [];

		foreach ( $response['headers'] as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				$headers[ strtolower( $key ) ] = trim( (string) $value );
			}
		}

		return $headers;
	}

	/**
	 * @return array{
	 *   status: int,
	 *   data: mixed,
	 *   json_error: int,
	 *   headers: array<string, string>,
	 *   body_bytes: int
	 * }|\WP_Error
	 */
	private static function normalize_json_response( $response, string $label ): array|\WP_Error {
		$status           = wp_remote_retrieve_response_code( $response );
		$raw_body         = wp_remote_retrieve_body( $response );
		$data             = json_decode( $raw_body, true );
		$response_headers = self::extract_response_headers( $response );

		if ( 429 === $status ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] )
				? $data['error']['message']
				: "{$label} is temporarily rate limited.";

			return self::build_retryable_rate_limit_error(
				'rate_limited',
				$message,
				wp_remote_retrieve_header( $response, 'retry-after' ),
				[
					'http_status'         => $status,
					'response_headers'    => $response_headers,
					'response_body_bytes' => strlen( $raw_body ),
				]
			);
		}

		return [
			'status'     => $status,
			'data'       => $data,
			'json_error' => json_last_error(),
			'headers'    => $response_headers,
			'body_bytes' => strlen( $raw_body ),
		];
	}

	/**
	 * @param array<string, string> $fields
	 * @param array{name:string,filename:string,contents:string,content_type:string} $file
	 */
	private static function build_multipart_body( string $boundary, array $fields, array $file ): string {
		$lines     = [];
		$file_name = self::sanitize_multipart_token( $file['name'] );
		$filename  = self::sanitize_multipart_token( $file['filename'] );

		$lines[] = '--' . $boundary;
		$lines[] = sprintf(
			'Content-Disposition: form-data; name="%s"; filename="%s"',
			$file_name,
			$filename
		);
		$lines[] = 'Content-Type: ' . trim( $file['content_type'] );
		$lines[] = '';
		$lines[] = $file['contents'];

		foreach ( $fields as $name => $value ) {
			$lines[] = '--' . $boundary;
			$lines[] = sprintf(
				'Content-Disposition: form-data; name="%s"',
				self::sanitize_multipart_token( $name )
			);
			$lines[] = '';
			$lines[] = $value;
		}

		$lines[] = '--' . $boundary . '--';
		$lines[] = '';

		return implode( "\r\n", $lines );
	}

	private static function sanitize_multipart_token( string $value ): string {
		return str_replace( [ "\r", "\n", '"' ], '', $value );
	}
}
