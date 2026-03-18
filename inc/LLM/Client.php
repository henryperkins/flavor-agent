<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

final class Client {

	private const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * Send a chat request to the Anthropic Messages API.
	 *
	 * @param string $system_prompt System instruction.
	 * @param string $user_prompt   User message.
	 * @param string $api_key       Anthropic API key (caller validates non-empty).
	 * @param string $model         Model ID.
	 * @return string|\WP_Error     The assistant's text response, or WP_Error.
	 */
	public static function chat( string $system_prompt, string $user_prompt, string $api_key = '', string $model = '' ): string|\WP_Error {
		if ( empty( $api_key ) ) {
			$api_key = get_option( 'flavor_agent_api_key', '' );
		}
		if ( empty( $model ) ) {
			$model = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );
		}
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', 'Anthropic API key not configured.', [ 'status' => 400 ] );
		}

		$body = wp_json_encode(
			[
				'model'      => $model,
				'max_tokens' => 4096,
				'system'     => $system_prompt,
				'messages'   => [
					[
						'role'    => 'user',
						'content' => $user_prompt,
					],
				],
			]
		);

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 60,
				'headers' => [
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status !== 200 ) {
			$msg = $data['error']['message'] ?? "API returned HTTP {$status}";
			return new \WP_Error( 'api_error', $msg, [ 'status' => 502 ] );
		}

		$text = $data['content'][0]['text'] ?? '';
		if ( empty( $text ) ) {
			return new \WP_Error( 'empty_response', 'LLM returned an empty response.', [ 'status' => 502 ] );
		}

		return $text;
	}
}
