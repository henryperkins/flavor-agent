<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

final class EmbeddingClient {

	public static function validate_configuration(
		?string $endpoint = null,
		?string $api_key = null,
		?string $deployment = null
	): true|\WP_Error {
		return ConfigurationValidator::validate(
			(string) ( $endpoint ?? get_option( 'flavor_agent_azure_openai_endpoint', '' ) ),
			(string) ( $api_key ?? get_option( 'flavor_agent_azure_openai_key', '' ) ),
			(string) ( $deployment ?? get_option( 'flavor_agent_azure_embedding_deployment', '' ) ),
			'/openai/v1/embeddings',
			[
				'input' => [ 'validation' ],
			],
			'embedding_validation_error',
			'Azure OpenAI embeddings'
		);
	}

	/**
	 * Embed a single input string.
	 *
	 * @return float[]|\WP_Error 3072-dimension vector.
	 */
	public static function embed( string $input ): array|\WP_Error {
		$result = self::embed_batch( [ $input ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result[0];
	}

	/**
	 * Embed multiple inputs in a single request (max ~2048).
	 *
	 * @param string[] $inputs
	 * @return float[][]|\WP_Error Array of vectors.
	 */
	public static function embed_batch( array $inputs ): array|\WP_Error {
		$endpoint   = get_option( 'flavor_agent_azure_openai_endpoint', '' );
		$api_key    = get_option( 'flavor_agent_azure_openai_key', '' );
		$deployment = get_option( 'flavor_agent_azure_embedding_deployment', '' );

		if ( empty( $endpoint ) || empty( $api_key ) || empty( $deployment ) ) {
			return new \WP_Error(
				'missing_credentials',
				'Azure OpenAI embedding credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		$url  = rtrim( $endpoint, '/' ) . '/openai/v1/embeddings';
		$body = wp_json_encode(
			[
				'model' => $deployment,
				'input' => $inputs,
			]
		);

		$data = self::request( $url, $api_key, $body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$vectors = [];
		foreach ( $data['data'] as $item ) {
			$vectors[] = $item['embedding'];
		}

		return $vectors;
	}

	/**
	 * @return array|\WP_Error Decoded response body.
	 */
	private static function request( string $url, string $api_key, string $body, bool $is_retry = false ): array|\WP_Error {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 30,
				'headers' => [
					'Content-Type' => 'application/json',
					'api-key'      => $api_key,
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status === 429 && ! $is_retry ) {
			$retry_after_header = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after        = (int) ( false !== $retry_after_header ? $retry_after_header : 2 );
			sleep( min( $retry_after, 10 ) );
			return self::request( $url, $api_key, $body, true );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $status !== 200 ) {
			$msg = $data['error']['message'] ?? "Azure OpenAI embeddings returned HTTP {$status}";
			return new \WP_Error( 'embedding_error', $msg, [ 'status' => 502 ] );
		}

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['data'] ) ) {
			return new \WP_Error( 'embedding_parse_error', 'Failed to parse embedding response.', [ 'status' => 502 ] );
		}

		return $data;
	}
}
