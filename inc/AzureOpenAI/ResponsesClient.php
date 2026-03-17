<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

final class ResponsesClient {

	/**
	 * Send a ranking/instruction request to the Azure OpenAI Responses API.
	 *
	 * @return string|\WP_Error The assistant's text response.
	 */
	public static function rank( string $instructions, string $input ): string|\WP_Error {
		$endpoint   = get_option( 'flavor_agent_azure_openai_endpoint', '' );
		$api_key    = get_option( 'flavor_agent_azure_openai_key', '' );
		$deployment = get_option( 'flavor_agent_azure_chat_deployment', '' );

		if ( empty( $endpoint ) || empty( $api_key ) || empty( $deployment ) ) {
			return new \WP_Error(
				'missing_credentials',
				'Azure OpenAI chat credentials are not configured. Go to Settings > Flavor Agent.',
				[ 'status' => 400 ]
			);
		}

		$url  = rtrim( $endpoint, '/' ) . '/openai/v1/responses';
		$body = wp_json_encode( [
			'model'        => $deployment,
			'instructions' => $instructions,
			'input'        => $input,
		] );

		return self::request( $url, $api_key, $body );
	}

	/**
	 * @return string|\WP_Error The text content from the response.
	 */
	private static function request( string $url, string $api_key, string $body, bool $is_retry = false ): string|\WP_Error {
		$response = wp_remote_post( $url, [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
				'api-key'      => $api_key,
			],
			'body' => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status === 429 && ! $is_retry ) {
			$retry_after = (int) ( wp_remote_retrieve_header( $response, 'retry-after' ) ?: 2 );
			sleep( min( $retry_after, 10 ) );
			return self::request( $url, $api_key, $body, true );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( $status !== 200 ) {
			$msg = $data['error']['message'] ?? "Azure OpenAI responses returned HTTP {$status}";
			return new \WP_Error( 'responses_error', $msg, [ 'status' => 502 ] );
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'responses_parse_error', 'Failed to parse Responses API response.', [ 'status' => 502 ] );
		}

		// Responses API: try output[0].content[0].text, then output_text fallback.
		$text = $data['output'][0]['content'][0]['text'] ?? '';
		if ( empty( $text ) ) {
			$text = $data['output_text'] ?? '';
		}

		if ( empty( $text ) ) {
			return new \WP_Error( 'empty_response', 'Azure OpenAI Responses API returned no text.', [ 'status' => 502 ] );
		}

		return $text;
	}
}
