<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

final class ConfigurationValidator {

	/**
	 * @param array<string, mixed> $body
	 */
	public static function validate(
		string $endpoint,
		string $api_key,
		string $deployment,
		string $path,
		array $body,
		string $error_code,
		string $fallback_message,
		string $expected_shape
	): true|\WP_Error {
		$endpoint   = trim( $endpoint );
		$api_key    = trim( $api_key );
		$deployment = trim( $deployment );

		if ( '' === $endpoint || '' === $api_key || '' === $deployment ) {
			return new \WP_Error(
				'missing_credentials',
				'Azure OpenAI credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		$body['model']   = $deployment;
		$encoded_body    = wp_json_encode( $body );

		if ( false === $encoded_body ) {
			return new \WP_Error(
				$error_code . '_encode_error',
				"Failed to encode {$fallback_message} validation request.",
				[ 'status' => 400 ]
			);
		}

		$response = wp_remote_post(
			rtrim( $endpoint, '/' ) . $path,
			[
				'timeout' => 20,
				'headers' => [
					'Content-Type' => 'application/json',
					'api-key'      => $api_key,
				],
				'body'    => $encoded_body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
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
			if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) && '' !== $data['output_text'] ) {
				return true;
			}

			if ( ! isset( $data['output'] ) || ! is_array( $data['output'] ) ) {
				return false;
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
						'' !== $content_item['text']
					) {
						return true;
					}
				}
			}
		}

		return false;
	}
}
