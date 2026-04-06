<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

abstract class BaseHttpClient {

	private const MAX_RETRY_AFTER_SECONDS = 60;

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, data: mixed, json_error: int}|\WP_Error
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

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $status ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] )
				? $data['error']['message']
				: "{$label} is temporarily rate limited.";

			return self::build_retryable_rate_limit_error(
				'rate_limited',
				$message,
				wp_remote_retrieve_header( $response, 'retry-after' )
			);
		}

		return [
			'status'     => $status,
			'data'       => $data,
			'json_error' => json_last_error(),
		];
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
		string|false $header
	): \WP_Error {
		$retry_after = self::parse_retry_after_header( $header );

		return new \WP_Error(
			$code,
			$message,
			[
				'status'          => 429,
				'retryable'       => true,
				'retry_after'     => $retry_after,
				'retry_after_raw' => false !== $header ? trim( $header ) : null,
			]
		);
	}
}
