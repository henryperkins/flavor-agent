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
	): array|\WP_Error {
		$provider = Provider::normalize_provider( $provider ?? Provider::get() );

		if ( Provider::is_connector( $provider ) ) {
			return new \WP_Error(
				'embedding_validation_error',
				sprintf(
					'%s does not currently expose embedding generation through Settings > Connectors. Choose Azure OpenAI or OpenAI Native in Settings > Flavor Agent for pattern recommendations.',
					Provider::label( $provider )
				),
				[ 'status' => 400 ]
			);
		}

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

		$data = ConfigurationValidator::validate_with_response(
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

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$vectors = self::extract_vectors_from_response( $data );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		return [
			'dimension' => count( $vectors[0] ),
			'signature' => self::build_signature_for_dimension( count( $vectors[0] ), $config ),
		];
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

		if ( Provider::is_connector( $config['provider'] ) ) {
			return new \WP_Error(
				'embedding_unsupported',
				sprintf(
					'%s does not currently expose embedding generation through Settings > Connectors. Choose Azure OpenAI or OpenAI Native in Settings > Flavor Agent for pattern recommendations.',
					Provider::label( $config['provider'] )
				),
				[ 'status' => 400 ]
			);
		}

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

		return self::extract_vectors_from_response( $data );
	}

	/**
	 * @param array{provider?: string, model?: string}|null $config
	 * @return array{provider: string, model: string, dimension: int, signature_hash: string}
	 */
	public static function build_signature_for_dimension( int $dimension, ?array $config = null ): array {
		return EmbeddingSignature::from_configuration(
			$config ?? Provider::embedding_configuration(),
			$dimension
		);
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

	/**
	 * @param array<string, mixed> $data
	 * @return float[][]|\WP_Error
	 */
	private static function extract_vectors_from_response( array $data ): array|\WP_Error {
		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) || [] === $data['data'] ) {
			return new \WP_Error(
				'embedding_parse_error',
				'Failed to parse embedding response.',
				[ 'status' => 502 ]
			);
		}

		$vectors   = [];
		$dimension = null;

		foreach ( $data['data'] as $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'embedding', $item ) ) {
				return new \WP_Error(
					'embedding_parse_error',
					'Failed to parse embedding response.',
					[ 'status' => 502 ]
				);
			}

			if ( ! is_array( $item['embedding'] ) ) {
				return new \WP_Error(
					'embedding_parse_error',
					'Embedding response contained a non-array embedding vector.',
					[ 'status' => 502 ]
				);
			}

			if ( [] === $item['embedding'] ) {
				return new \WP_Error(
					'embedding_parse_error',
					'Embedding response contained an empty embedding vector.',
					[ 'status' => 502 ]
				);
			}

			$vector = array_map( 'floatval', $item['embedding'] );

			if ( null === $dimension ) {
				$dimension = count( $vector );
			} elseif ( count( $vector ) !== $dimension ) {
				return new \WP_Error(
					'embedding_dimension_mismatch',
					'Embedding response contained inconsistent embedding dimensions.',
					[
						'status'             => 502,
						'expected_dimension' => $dimension,
						'actual_dimension'   => count( $vector ),
					]
				);
			}

			$vectors[] = $vector;
		}

		return $vectors;
	}
}
