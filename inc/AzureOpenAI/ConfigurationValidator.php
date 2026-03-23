<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

final class ConfigurationValidator {

	private const REQUEST_TIMEOUT = 30;

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, string> $headers
	 */
	public static function validate(
		string $url,
		array $headers,
		string $model,
		array $body,
		string $error_code,
		string $fallback_message,
		string $expected_shape
	): true|\WP_Error {
		$url             = trim( $url );
		$model           = trim( $model );
		$has_auth_header = false;

		foreach ( $headers as $header_name => $header_value ) {
			if ( strtolower( $header_name ) === 'content-type' ) {
				continue;
			}

			if ( trim( (string) $header_value ) !== '' ) {
				$has_auth_header = true;
				break;
			}
		}

		if ( '' === $url || '' === $model || ! $has_auth_header ) {
			return new \WP_Error(
				'missing_credentials',
				'OpenAI credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		$body['model'] = $model;
		$encoded_body  = wp_json_encode( $body );

		if ( false === $encoded_body ) {
			return new \WP_Error(
				$error_code . '_encode_error',
				"Failed to encode {$fallback_message} validation request.",
				[ 'status' => 400 ]
			);
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => $headers,
				'body'    => $encoded_body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return self::normalize_transport_error(
				$response,
				$fallback_message,
				$url,
				self::REQUEST_TIMEOUT
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status !== 200 ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] )
				? $data['error']['message']
				: $fallback_message . " returned HTTP {$status}";

			return new \WP_Error(
				$error_code,
				$message,
				[ 'status' => 400 ]
			);
		}

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return new \WP_Error(
				$error_code . '_parse_error',
				"Failed to parse {$fallback_message} validation response.",
				[ 'status' => 400 ]
			);
		}

		if ( ! self::matches_expected_shape( $data, $expected_shape ) ) {
			return new \WP_Error(
				$error_code . '_invalid_shape',
				"Unexpected {$fallback_message} validation response format.",
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	public static function normalize_transport_error( \WP_Error $error, string $label, string $url, int $timeout ): \WP_Error {
		$message = $error->get_error_message();
		$lower   = strtolower( $message );

		if (
			str_contains( $lower, 'curl error 28' )
			|| str_contains( $lower, 'operation timed out' )
		) {
			$host = (string) parse_url( $url, PHP_URL_HOST );
			$host = '' !== $host ? $host : $url;

			return new \WP_Error(
				$error->get_error_code(),
				sprintf(
					'%s request timed out after %d seconds while contacting %s. Check outbound HTTPS connectivity from this server, or increase the request timeout.',
					$label,
					$timeout,
					$host
				),
				[
					'status'  => 504,
					'wrapped' => $message,
				]
			);
		}

		return $error;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function matches_expected_shape( array $data, string $expected_shape ): bool {
		if ( 'embeddings' === $expected_shape ) {
			if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) || [] === $data['data'] ) {
				return false;
			}

			foreach ( $data['data'] as $item ) {
				if (
					is_array( $item ) &&
					isset( $item['embedding'] ) &&
					is_array( $item['embedding'] ) &&
					[] !== $item['embedding']
				) {
					return true;
				}
			}

			return false;
		}

		if ( 'responses' === $expected_shape ) {
			if ( '' !== self::extract_response_text( $data ) ) {
				return true;
			}

			return self::is_response_object( $data );
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function extract_response_text( array $data ): string {
		if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) && '' !== trim( $data['output_text'] ) ) {
			return trim( $data['output_text'] );
		}

		if ( ! isset( $data['output'] ) || ! is_array( $data['output'] ) ) {
			return '';
		}

		foreach ( $data['output'] as $output_item ) {
			if ( ! is_array( $output_item ) || ! isset( $output_item['content'] ) || ! is_array( $output_item['content'] ) ) {
				continue;
			}

			foreach ( $output_item['content'] as $content_item ) {
				if (
					is_array( $content_item ) &&
					isset( $content_item['text'] ) &&
					is_string( $content_item['text'] ) &&
					'' !== trim( $content_item['text'] )
				) {
					return trim( $content_item['text'] );
				}
			}
		}

		return '';
	}

	/**
	 * Accept a Responses API object even when text is incomplete due to token limits.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function is_response_object( array $data ): bool {
		if ( ( $data['object'] ?? '' ) !== 'response' ) {
			return false;
		}

		if ( ! isset( $data['status'] ) || ! is_string( $data['status'] ) || '' === $data['status'] ) {
			return false;
		}

		return isset( $data['output'] ) && is_array( $data['output'] );
	}
}
