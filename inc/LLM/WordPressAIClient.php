<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use WordPress\AI_Client\AI_Client;

final class WordPressAIClient {

	private const SETUP_MESSAGE = 'Configure a text-generation provider in Settings > Connectors to enable block recommendations.';

	public static function is_supported( ?string $provider = null ): bool {
		$prompt = self::make_prompt( 'Flavor Agent availability check.' );

		if ( is_wp_error( $prompt ) ) {
			return false;
		}

		$prompt = self::apply_provider_selection( $prompt, $provider );

		if ( is_wp_error( $prompt ) ) {
			return false;
		}

		$supported = self::call_prompt_method( $prompt, 'is_supported_for_text_generation' );

		return ! is_wp_error( $supported ) && (bool) $supported;
	}

	public static function chat( string $system_prompt, string $user_prompt, ?string $provider = null ): string|\WP_Error {
		$prompt = self::make_prompt( $user_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$prompt = self::apply_provider_selection( $prompt, $provider );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$prompt = self::apply_system_instruction( $prompt, $system_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$supported = self::call_prompt_method( $prompt, 'is_supported_for_text_generation' );

		if ( is_wp_error( $supported ) ) {
			return $supported;
		}

		if ( ! $supported ) {
			return new \WP_Error(
				'missing_text_generation_provider',
				self::SETUP_MESSAGE,
				[ 'status' => 400 ]
			);
		}

		$result = self::call_prompt_method( $prompt, 'generate_text' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_string( $result ) || '' === trim( $result ) ) {
			return new \WP_Error(
				'empty_response',
				'The WordPress AI client returned an empty response.',
				[ 'status' => 502 ]
			);
		}

		return $result;
	}

	public static function get_setup_message(): string {
		return self::SETUP_MESSAGE;
	}

	/**
	 * @return object|\WP_Error
	 */
	private static function make_prompt( string $user_prompt ): mixed {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error(
				'wp_ai_client_unavailable',
				'WordPress AI Client is unavailable. Flavor Agent requires WordPress 7.0+ with the built-in AI client.',
				[ 'status' => 500 ]
			);
		}

		$prompt = wp_ai_client_prompt( $user_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		if ( ! is_object( $prompt ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				'WordPress AI Client did not return a prompt builder.',
				[ 'status' => 500 ]
			);
		}

		return $prompt;
	}

	/**
	 * @return object|\WP_Error
	 */
	private static function apply_provider_selection( object $prompt, ?string $provider ) {
		$provider = is_string( $provider ) ? sanitize_key( $provider ) : '';

		if ( '' === $provider ) {
			return $prompt;
		}

		$updated_prompt = self::call_prompt_method( $prompt, 'using_provider', [ $provider ] );

		if ( is_wp_error( $updated_prompt ) ) {
			return $updated_prompt;
		}

		if ( ! is_object( $updated_prompt ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				'WordPress AI Client did not return a prompt builder.',
				[ 'status' => 500 ]
			);
		}

		return $updated_prompt;
	}

	/**
	 * @return object|\WP_Error
	 */
	private static function apply_system_instruction( object $prompt, string $system_prompt ): object {
		if ( ! is_callable( [ $prompt, 'using_system_instruction' ] ) ) {
			return $prompt;
		}

		$updated_prompt = self::call_prompt_method( $prompt, 'using_system_instruction', [ $system_prompt ] );

		if ( is_wp_error( $updated_prompt ) ) {
			return $updated_prompt;
		}

		if ( ! is_object( $updated_prompt ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				'WordPress AI Client did not return a prompt builder.',
				[ 'status' => 500 ]
			);
		}

		return $updated_prompt;
	}

	private static function call_prompt_method( object $prompt, string $method, array $arguments = [] ): mixed {
		if ( ! is_callable( [ $prompt, $method ] ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				sprintf( 'WordPress AI Client prompt builder does not support %s.', $method ),
				[ 'status' => 500 ]
			);
		}

		try {
			return $prompt->{$method}( ...$arguments );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error(
				'wp_ai_client_request_failed',
				$throwable->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}
}
