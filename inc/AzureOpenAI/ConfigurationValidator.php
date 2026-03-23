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
		string $fallback_message
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

		$body['model'] = $deployment;
		$response      = wp_remote_post(
			rtrim( $endpoint, '/' ) . $path,
			[
				'timeout' => 20,
				'headers' => [
					'Content-Type' => 'application/json',
					'api-key'      => $api_key,
				],
				'body'    => wp_json_encode( $body ),
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

		return true;
	}
}
