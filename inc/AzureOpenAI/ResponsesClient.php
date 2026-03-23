<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

use FlavorAgent\OpenAI\Provider;

final class ResponsesClient {

	private const REASONING_EFFORT = 'high';

	public static function validate_configuration(
		?string $endpoint = null,
		?string $api_key = null,
		?string $deployment = null,
		?string $provider = null
	): true|\WP_Error {
		$provider = Provider::normalize_provider( $provider ?? Provider::get() );
		$config   = Provider::chat_configuration(
			$provider,
			Provider::is_native( $provider )
				? [
					'flavor_agent_openai_native_api_key' => (string) ( $api_key ?? get_option( 'flavor_agent_openai_native_api_key', '' ) ),
					'flavor_agent_openai_native_chat_model' => (string) ( $deployment ?? get_option( 'flavor_agent_openai_native_chat_model', '' ) ),
				]
				: [
					'flavor_agent_azure_openai_endpoint' => (string) ( $endpoint ?? get_option( 'flavor_agent_azure_openai_endpoint', '' ) ),
					'flavor_agent_azure_openai_key'      => (string) ( $api_key ?? get_option( 'flavor_agent_azure_openai_key', '' ) ),
					'flavor_agent_azure_chat_deployment' => (string) ( $deployment ?? get_option( 'flavor_agent_azure_chat_deployment', '' ) ),
				]
		);

		return ConfigurationValidator::validate(
			$config['url'],
			$config['headers'],
			$config['model'],
			[
				'input'             => 'validation',
				'max_output_tokens' => 16,
				'reasoning'         => self::reasoning_options(),
			],
			'responses_validation_error',
			$config['label'],
			'responses'
		);
	}

	/**
	 * Send a ranking/instruction request to the Azure OpenAI Responses API.
	 *
	 * @return string|\WP_Error The assistant's text response.
	 */
	public static function rank( string $instructions, string $input ): string|\WP_Error {
		$config = Provider::chat_configuration();

		if ( ! $config['configured'] ) {
			return new \WP_Error(
				'missing_credentials',
				sprintf(
					'%s chat credentials are not configured. Go to Settings > Flavor Agent.',
					Provider::label( $config['provider'] )
				),
				[ 'status' => 400 ]
			);
		}

		$body = wp_json_encode(
			[
				'model'        => $config['model'],
				'instructions' => $instructions,
				'input'        => $input,
				'reasoning'    => self::reasoning_options(),
			]
		);

		return self::request( $config['url'], $config['headers'], $body, $config['label'] );
	}

	/**
	 * @return array{effort: string}
	 */
	private static function reasoning_options(): array {
		return [
			'effort' => self::REASONING_EFFORT,
		];
	}

	/**
	 * @return string|\WP_Error The text content from the response.
	 */
	private static function request( string $url, array $headers, string $body, string $label, bool $is_retry = false ): string|\WP_Error {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 30,
				'headers' => $headers,
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
			return self::request( $url, $headers, $body, $label, true );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $status !== 200 ) {
			$msg = $data['error']['message'] ?? "{$label} returned HTTP {$status}";
			return new \WP_Error( 'responses_error', $msg, [ 'status' => 502 ] );
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'responses_parse_error', 'Failed to parse Responses API response.', [ 'status' => 502 ] );
		}

		$text = ConfigurationValidator::extract_response_text( is_array( $data ) ? $data : [] );

		if ( empty( $text ) ) {
			return new \WP_Error( 'empty_response', "{$label} returned no text.", [ 'status' => 502 ] );
		}

		return $text;
	}
}
