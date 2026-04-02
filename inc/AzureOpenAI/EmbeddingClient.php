<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

use FlavorAgent\OpenAI\Provider;

final class EmbeddingClient extends BaseHttpClient {

	private const REQUEST_TIMEOUT = 60;

	public static function validate_configuration(
		?string $endpoint = null,
		?string $api_key = null,
		?string $deployment = null,
		?string $provider = null
	): true|\WP_Error {
		$provider = Provider::normalize_provider( $provider ?? Provider::get() );
		$config   = Provider::embedding_configuration(
			$provider,
			Provider::is_native( $provider )
				? [
					'flavor_agent_openai_native_api_key' => (string) ( $api_key ?? get_option( 'flavor_agent_openai_native_api_key', '' ) ),
					'flavor_agent_openai_native_embedding_model' => (string) ( $deployment ?? get_option( 'flavor_agent_openai_native_embedding_model', '' ) ),
				]
				: [
					'flavor_agent_azure_openai_endpoint' => (string) ( $endpoint ?? get_option( 'flavor_agent_azure_openai_endpoint', '' ) ),
					'flavor_agent_azure_openai_key'      => (string) ( $api_key ?? get_option( 'flavor_agent_azure_openai_key', '' ) ),
					'flavor_agent_azure_embedding_deployment' => (string) ( $deployment ?? get_option( 'flavor_agent_azure_embedding_deployment', '' ) ),
				]
		);

		return ConfigurationValidator::validate(
			$config['url'],
			$config['headers'],
			$config['model'],
			[
				'input' => [ 'validation' ],
			],
			'embedding_validation_error',
			$config['label'],
			'embeddings'
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
		$config = Provider::embedding_configuration();

		if ( ! $config['configured'] ) {
			return new \WP_Error(
				'missing_credentials',
				sprintf(
					'%s embedding credentials are not configured. Go to Settings > Flavor Agent.',
					Provider::label( $config['provider'] )
				),
				[ 'status' => 400 ]
			);
		}

		$body = wp_json_encode(
			[
				'model' => $config['model'],
				'input' => $inputs,
			]
		);

		$data = self::request( $config['url'], $config['headers'], $body, $config['label'] );
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
	private static function request( string $url, array $headers, string $body, string $label ): array|\WP_Error {
		$response = self::post_json_with_retry(
			$url,
			$headers,
			$body,
			$label,
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = $response['status'];
		$data   = $response['data'];

		if ( $status !== 200 ) {
			$msg = is_array( $data ) ? ( $data['error']['message'] ?? "{$label} returned HTTP {$status}" ) : "{$label} returned HTTP {$status}";
			return new \WP_Error( 'embedding_error', $msg, [ 'status' => 502 ] );
		}

		if ( JSON_ERROR_NONE !== $response['json_error'] || ! is_array( $data ) || empty( $data['data'] ) ) {
			return new \WP_Error( 'embedding_parse_error', 'Failed to parse embedding response.', [ 'status' => 502 ] );
		}

		return $data;
	}
}
