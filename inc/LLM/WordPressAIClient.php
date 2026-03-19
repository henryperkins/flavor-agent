<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use WordPress\AI_Client\AI_Client;

final class WordPressAIClient {

	private const SETUP_MESSAGE = 'Configure a text-generation provider in Settings > Connectors to enable block recommendations.';

	public static function is_supported(): bool {
		$prompt = self::make_prompt( 'Flavor Agent availability check.' );

		if ( is_wp_error( $prompt ) ) {
			return false;
		}

		if ( ! method_exists( $prompt, 'is_supported_for_text_generation' ) ) {
			return false;
		}

		return (bool) $prompt->is_supported_for_text_generation();
	}

	public static function chat( string $system_prompt, string $user_prompt ): string|\WP_Error {
		$prompt = self::make_prompt( $user_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		if ( method_exists( $prompt, 'using_system_instruction' ) ) {
			$prompt = $prompt->using_system_instruction( $system_prompt );
		}

		if (
			method_exists( $prompt, 'is_supported_for_text_generation' )
			&& ! $prompt->is_supported_for_text_generation()
		) {
			return new \WP_Error(
				'missing_text_generation_provider',
				self::SETUP_MESSAGE,
				[ 'status' => 400 ]
			);
		}

		$result = $prompt->generate_text();

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
		if (
			! class_exists( AI_Client::class )
			|| ! method_exists( AI_Client::class, 'prompt_with_wp_error' )
		) {
			return new \WP_Error(
				'wp_ai_client_unavailable',
				'WordPress AI Client is unavailable. Flavor Agent now requires WordPress 7.0+ with the built-in AI client.',
				[ 'status' => 500 ]
			);
		}

		$prompt = AI_Client::prompt_with_wp_error( $user_prompt );

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
}
