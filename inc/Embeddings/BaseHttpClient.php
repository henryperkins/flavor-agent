<?php

declare(strict_types=1);

namespace FlavorAgent\Embeddings;

abstract class BaseHttpClient {

	private const MAX_RETRY_AFTER_SECONDS = 60;
	// Cloudflare reports monthly AI Search query, storage, and ingestion quotas
	// as HTTP 429, but waiting cannot clear them.
	private const CLOUDFLARE_QUOTA_ERROR_CODES = [ 7069, 7112, 7116 ];

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
	protected static function post_json(
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
	protected static function request_json(
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
	protected static function post_multipart(
		string $url,
		array $headers,
		array $fields,
		array $file,
		string $label,
		int $timeout
	): array|\WP_Error {
		$boundary                = 'flavor-agent-' . md5( $url . '|' . microtime( true ) );
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		return self::request_json(
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

	/**
	 * AI Search item uploads accept custom metadata as a JSON string whose values
	 * are string form fields, even when the configured metadata schema casts them.
	 *
	 * @param array<string, mixed> $metadata
	 */
	protected static function encode_item_upload_metadata( array $metadata ): string {
		$normalized = [];

		foreach ( $metadata as $key => $value ) {
			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$normalized[ $key ] = $value ? 'true' : 'false';
				continue;
			}

			if ( null === $value || is_scalar( $value ) ) {
				$normalized[ $key ] = (string) $value;
			}
		}

		$encoded = wp_json_encode( $normalized );

		return is_string( $encoded ) ? $encoded : '';
	}

	public static function parse_retry_after_header(
		string|false $header,
		?int $now = null,
		int $max_seconds = self::MAX_RETRY_AFTER_SECONDS
	): ?int {
		if ( false === $header ) {
			return null;
		}

		$header = trim( $header );

		if ( '' === $header ) {
			return null;
		}

		if ( preg_match( '/^\d+$/', $header ) ) {
			return max( 1, min( $max_seconds, (int) $header ) );
		}

		$retry_at = strtotime( $header );

		if ( false === $retry_at ) {
			return null;
		}

		$delay = $retry_at - ( $now ?? time() );

		return max( 1, min( $max_seconds, $delay ) );
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
			$cloudflare_error = self::extract_cloudflare_error( $data );
			$extra_data       = [
				'http_status'         => $status,
				'response_headers'    => $response_headers,
				'response_body_bytes' => strlen( $raw_body ),
			];

			if ( null !== $cloudflare_error ) {
				$extra_data['remote_error_code'] = $cloudflare_error['code'];
			}

			if ( null !== $cloudflare_error && in_array( $cloudflare_error['code'], self::CLOUDFLARE_QUOTA_ERROR_CODES, true ) ) {
				return new \WP_Error(
					'quota_exceeded',
					sprintf(
						'%1$s was rejected because the Cloudflare account reached a usage quota (%2$s). Waiting will not clear it; review the Cloudflare plan or usage limits.',
						$label,
						self::describe_cloudflare_error( $cloudflare_error )
					),
					array_merge(
						[
							'status'      => 429,
							'retryable'   => false,
							'retry_after' => null,
						],
						$extra_data
					)
				);
			}

			if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			} elseif ( null !== $cloudflare_error ) {
				$message = sprintf(
					'%1$s is temporarily rate limited (%2$s).',
					$label,
					self::describe_cloudflare_error( $cloudflare_error )
				);
			} else {
				$message = "{$label} is temporarily rate limited.";
			}

			return self::build_retryable_rate_limit_error(
				'rate_limited',
				$message,
				wp_remote_retrieve_header( $response, 'retry-after' ),
				$extra_data
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
	 * Cloudflare v4 responses report failures as `errors: [{code, message}]`.
	 *
	 * @return array{code: int, message: string}|null
	 */
	private static function extract_cloudflare_error( mixed $data ): ?array {
		$error = is_array( $data ) && is_array( $data['errors'] ?? null ) ? reset( $data['errors'] ) : null;

		if ( ! is_array( $error ) || ! is_numeric( $error['code'] ?? null ) || (int) $error['code'] <= 0 ) {
			return null;
		}

		return [
			'code'    => (int) $error['code'],
			'message' => is_string( $error['message'] ?? null ) ? sanitize_text_field( $error['message'] ) : '',
		];
	}

	/**
	 * @param array{code: int, message: string} $error
	 */
	private static function describe_cloudflare_error( array $error ): string {
		return '' !== $error['message']
			? sprintf( 'Cloudflare error %1$d: %2$s', $error['code'], $error['message'] )
			: sprintf( 'Cloudflare error %d', $error['code'] );
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
