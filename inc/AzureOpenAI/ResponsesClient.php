<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Support\WordPressAIPolicy;

/**
 * Thin compatibility facade for legacy callers. Chat is owned by core
 * Settings > Connectors via the WordPress AI Client; this class only translates
 * the legacy rank() signature into a WordPressAIClient::chat() call.
 */
final class ResponsesClient {

	private const DEFAULT_REASONING_EFFORT = 'medium';

	public static function validate_configuration(
		?string $endpoint = null,
		?string $api_key = null,
		?string $deployment = null,
		?string $provider = null
	): true|\WP_Error {
		unset( $endpoint, $api_key, $deployment );

		$config = null === $provider
			? Provider::chat_configuration()
			: Provider::chat_configuration( $provider );

		if ( $config['configured'] ) {
			return true;
		}

		return new \WP_Error(
			'responses_validation_error',
			__( 'Chat is owned by Settings > Connectors. Configure a text-generation provider there to enable Flavor Agent recommendations.', 'flavor-agent' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Send a ranking/instruction request through the WordPress AI Client.
	 *
	 * @param array<string, mixed>|null $schema Optional JSON schema to constrain the response.
	 * @return string|\WP_Error The assistant's text response.
	 */
	public static function rank(
		string $instructions,
		string $input,
		?string $reasoning_effort = null,
		?array $schema = null,
		?string $schema_name = null,
		?array $model_options = null
	): string|\WP_Error {
		Provider::record_runtime_chat_metrics( null );
		Provider::record_runtime_chat_diagnostics( null );

		$config = Provider::chat_configuration();

		if ( ! $config['configured'] ) {
			return new \WP_Error(
				'missing_text_generation_provider',
				__( 'Configure a text-generation provider in Settings > Connectors to enable Flavor Agent recommendations.', 'flavor-agent' ),
				[ 'status' => 400 ]
			);
		}

		$pinned_connector = Provider::is_connector( $config['provider'] ) ? $config['provider'] : null;

		return WordPressAIClient::chat(
			$instructions,
			$input,
			$pinned_connector,
			self::resolve_reasoning_effort( $reasoning_effort ),
			$schema,
			$model_options,
			WordPressAIPolicy::ability_name_for_schema_name( $schema_name )
		);
	}

	private static function resolve_reasoning_effort( ?string $reasoning_effort ): string {
		$candidate = self::sanitize_reasoning_effort( $reasoning_effort );

		if ( null !== $candidate ) {
			return $candidate;
		}

		$saved = self::sanitize_reasoning_effort(
			(string) get_option( 'flavor_agent_azure_reasoning_effort', self::DEFAULT_REASONING_EFFORT )
		);

		return $saved ?? self::DEFAULT_REASONING_EFFORT;
	}

	private static function sanitize_reasoning_effort( ?string $reasoning_effort ): ?string {
		if ( ! is_string( $reasoning_effort ) || '' === $reasoning_effort ) {
			return null;
		}

		$candidate = sanitize_key( $reasoning_effort );

		return in_array( $candidate, [ 'low', 'medium', 'high', 'xhigh' ], true )
			? $candidate
			: null;
	}
}
