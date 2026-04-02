<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

abstract class BaseHttpClient {

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, data: mixed, json_error: int}|\WP_Error
	 */
	protected static function post_json_with_retry(
		string $url,
		array $headers,
		string $body,
		string $label,
		int $timeout,
		bool $is_retry = false
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

		if ( 429 === $status && ! $is_retry ) {
			// Preserve the existing one-shot retry behavior for transient rate limits.
			$retry_after_header = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after        = (int) ( false !== $retry_after_header ? $retry_after_header : 2 );
			sleep( min( $retry_after, 10 ) );

			return self::post_json_with_retry( $url, $headers, $body, $label, $timeout, true );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return [
			'status'     => $status,
			'data'       => $data,
			'json_error' => json_last_error(),
		];
	}
}
