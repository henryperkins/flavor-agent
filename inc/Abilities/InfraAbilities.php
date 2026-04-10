<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\ChatClient;
use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\OpenAI\Provider;

final class InfraAbilities {

	public static function get_theme_tokens( mixed $input ): array {
		return ServerCollector::for_tokens();
	}

	public static function check_status( mixed $input ): array {
		$block_recommendations_configured = ChatClient::is_supported();
		$wordpress_ai_client_configured   = WordPressAIClient::is_supported();
		$openai_connector_status          = Provider::openai_connector_status();
		$native_api_key_source            = Provider::native_effective_api_key_source();
		$native_embedding_config          = Provider::embedding_configuration( Provider::NATIVE );
		$native_chat_config               = Provider::chat_configuration( Provider::NATIVE );
		$azure_endpoint                   = get_option( 'flavor_agent_azure_openai_endpoint', '' );
		$azure_key                        = get_option( 'flavor_agent_azure_openai_key', '' );
		$azure_embedding                  = get_option( 'flavor_agent_azure_embedding_deployment', '' );
		$azure_chat                       = get_option( 'flavor_agent_azure_chat_deployment', '' );
		$qdrant_url                       = get_option( 'flavor_agent_qdrant_url', '' );
		$qdrant_key                       = get_option( 'flavor_agent_qdrant_key', '' );
		$cloudflare_ai_search_id          = AISearchClient::configured_instance_id();

		$azure_chat_configured         = ! empty( $azure_endpoint ) && ! empty( $azure_key ) && ! empty( $azure_chat );
		$openai_native_chat_configured = $native_chat_config['configured'];
		$qdrant_configured             = ! empty( $qdrant_url ) && ! empty( $qdrant_key );
		$cloudflare_configured         = AISearchClient::is_configured();
		$active_chat_configured        = Provider::chat_configured();
		$active_pattern_provider       = Provider::embedding_configured() && $active_chat_configured;
		$navigation_configured         = $active_chat_configured && current_user_can( 'edit_theme_options' );
		$settings_url                  = function_exists( 'admin_url' )
			? admin_url( 'options-general.php?page=flavor-agent' )
			: '';
		$connectors_url                = function_exists( 'admin_url' )
			? admin_url( 'options-connectors.php' )
			: '';

		$abilities = self::available_abilities(
			$block_recommendations_configured,
			$active_chat_configured,
			$active_pattern_provider,
			$qdrant_configured,
			$cloudflare_configured
		);

		return [
			'configured'         => $block_recommendations_configured || $active_chat_configured || ( $active_pattern_provider && $qdrant_configured ),
			'model'              => self::resolve_primary_model( $block_recommendations_configured, $active_chat_configured ),
			'availableAbilities' => $abilities,
			'surfaces'           => SurfaceCapabilities::build(
				$settings_url,
				$connectors_url
			),
			'backends'           => [
				'wordpress_ai_client'  => [
					'configured' => $wordpress_ai_client_configured,
				],
				'azure_openai'         => [
					'configured'          => $azure_chat_configured,
					'chatDeployment'      => $azure_chat_configured ? $azure_chat : null,
					'embeddingDeployment' => ! empty( $azure_embedding ) ? $azure_embedding : null,
				],
				'openai_native'        => [
					'configured'          => $openai_native_chat_configured,
					'chatModel'           => $openai_native_chat_configured ? $native_chat_config['model'] : null,
					'embeddingModel'      => ! empty( $native_embedding_config['model'] ) ? $native_embedding_config['model'] : null,
					'credentialSource'    => 'none' !== $native_api_key_source ? $native_api_key_source : null,
					'connectorRegistered' => $openai_connector_status['registered'],
					'connectorConfigured' => $openai_connector_status['configured'],
					'connectorKeySource'  => 'none' !== $openai_connector_status['keySource']
						? $openai_connector_status['keySource']
						: null,
				],
				'qdrant'               => [
					'configured' => $qdrant_configured,
				],
				'cloudflare_ai_search' => [
					'configured' => $cloudflare_configured,
					'instanceId' => $cloudflare_configured ? $cloudflare_ai_search_id : null,
				],
			],
		];
	}

	private static function resolve_primary_model(
		bool $block_recommendations_configured,
		bool $active_chat_configured
	): ?string {
		if ( $active_chat_configured ) {
			return Provider::active_chat_model();
		}

		if ( $block_recommendations_configured ) {
			return 'provider-managed';
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private static function available_abilities(
		bool $block_recommendations_configured,
		bool $chat_configured,
		bool $pattern_provider_configured,
		bool $qdrant_configured,
		bool $cloudflare_configured
	): array {
		$abilities = [];

		self::maybe_add_ability( $abilities, 'flavor-agent/introspect-block', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-content', 'edit_posts', $chat_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/list-patterns', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/list-template-parts', 'edit_theme_options' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-theme-tokens', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/check-status', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-block', 'edit_posts', $block_recommendations_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-patterns', 'edit_posts', $pattern_provider_configured && $qdrant_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-template', 'edit_theme_options', $chat_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-template-part', 'edit_theme_options', $chat_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-navigation', 'edit_theme_options', $chat_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-style', 'edit_theme_options', $chat_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/search-wordpress-docs', WordPressDocsAbilities::REQUIRED_CAPABILITY, $cloudflare_configured );

		return $abilities;
	}

	/**
	 * @param string[] $abilities
	 */
	private static function maybe_add_ability( array &$abilities, string $ability, string $capability, bool $enabled = true ): void {
		if ( $enabled && current_user_can( $capability ) ) {
			$abilities[] = $ability;
		}
	}
}
