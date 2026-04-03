<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\OpenAI\Provider;

final class ChatClient {

	private const SETUP_MESSAGE = 'Configure Azure OpenAI or OpenAI Native in Settings > Flavor Agent, or configure a text-generation provider in Settings > Connectors, to enable block recommendations.';

	public static function is_supported(): bool {
		return Provider::chat_configured();
	}

	public static function chat( string $system_prompt, string $user_prompt ): string|\WP_Error {
		$result = Provider::chat_configured()
			? ResponsesClient::rank( $system_prompt, $user_prompt )
			: WordPressAIClient::chat( $system_prompt, $user_prompt );

		if (
			is_wp_error( $result )
			&& 'missing_text_generation_provider' === $result->get_error_code()
		) {
			return new \WP_Error(
				'missing_text_generation_provider',
				self::SETUP_MESSAGE,
				[ 'status' => 400 ]
			);
		}

		return $result;
	}

	public static function get_setup_message(): string {
		return self::SETUP_MESSAGE;
	}
}
