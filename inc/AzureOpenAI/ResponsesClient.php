<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\OpenAI\Provider;

final class ResponsesClient extends BaseHttpClient {

	private const REASONING_EFFORT = 'high';
	private const REQUEST_TIMEOUT  = 90;

	public static function validate_configuration(
		?string $endpoint = null,
		?string $api_key = null,
		?string $deployment = null,
		?string $provider = null
	): true|\WP_Error {
		$provider = Provider::normalize_provider( $provider ?? Provider::get() );

		if ( Provider::is_connector( $provider ) ) {
			if ( WordPressAIClient::is_supported( $provider ) ) {
				return true;
			}

			return new \WP_Error(
				'responses_validation_error',
				sprintf(
					'%s is not currently available through Settings > Connectors.',
					Provider::label( $provider )
				),
				[ 'status' => 400 ]
			);
		}

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
		$provider = Provider::get();

		if ( Provider::is_connector( $provider ) ) {
			return WordPressAIClient::chat( $instructions, $input, $provider );
		}

		$config = Provider::chat_configuration( $provider );

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
	private static function request( string $url, array $headers, string $body, string $label ): string|\WP_Error {
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
			return new \WP_Error( 'responses_error', $msg, [ 'status' => 502 ] );
		}

		if ( JSON_ERROR_NONE !== $response['json_error'] ) {
			return new \WP_Error( 'responses_parse_error', 'Failed to parse Responses API response.', [ 'status' => 502 ] );
		}

		$text = ConfigurationValidator::extract_response_text( is_array( $data ) ? $data : [] );

		if ( empty( $text ) ) {
			return new \WP_Error( 'empty_response', "{$label} returned no text.", [ 'status' => 502 ] );
		}

		return $text;
	}
}
