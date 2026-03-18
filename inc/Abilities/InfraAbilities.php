<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Context\ServerCollector;

final class InfraAbilities {

	public static function get_theme_tokens( mixed $input ): array {
		return ServerCollector::for_tokens();
	}

	public static function check_status( mixed $input ): array {
		$anthropic_key   = get_option( 'flavor_agent_api_key', '' );
		$anthropic_model = get_option( 'flavor_agent_model', 'claude-sonnet-4-20250514' );

		$azure_endpoint          = get_option( 'flavor_agent_azure_openai_endpoint', '' );
		$azure_key               = get_option( 'flavor_agent_azure_openai_key', '' );
		$azure_embedding         = get_option( 'flavor_agent_azure_embedding_deployment', '' );
		$azure_chat              = get_option( 'flavor_agent_azure_chat_deployment', '' );
		$qdrant_url              = get_option( 'flavor_agent_qdrant_url', '' );
		$qdrant_key              = get_option( 'flavor_agent_qdrant_key', '' );
		$cloudflare_ai_search_id = get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' );

		$anthropic_configured  = ! empty( $anthropic_key );
		$azure_chat_configured = ! empty( $azure_endpoint ) && ! empty( $azure_key ) && ! empty( $azure_chat );
		$azure_configured      = $azure_chat_configured && ! empty( $azure_embedding );
		$qdrant_configured     = ! empty( $qdrant_url ) && ! empty( $qdrant_key );
		$cloudflare_configured = AISearchClient::is_configured();

		$abilities = self::available_abilities(
			$anthropic_configured,
			$azure_chat_configured,
			$azure_configured,
			$qdrant_configured,
			$cloudflare_configured
		);

		return [
			'configured'         => $anthropic_configured || $azure_chat_configured || $cloudflare_configured || ( $azure_configured && $qdrant_configured ),
			'model'              => self::resolve_primary_model( $anthropic_configured, (string) $anthropic_model, $azure_chat_configured, (string) $azure_chat ),
			'availableAbilities' => $abilities,
			'backends'           => [
				'anthropic'            => [
					'configured' => $anthropic_configured,
					'model'      => $anthropic_configured ? $anthropic_model : null,
				],
				'azure_openai'         => [
					'configured'          => $azure_chat_configured,
					'chatDeployment'      => $azure_chat_configured ? $azure_chat : null,
					'embeddingDeployment' => ! empty( $azure_embedding ) ? $azure_embedding : null,
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
		bool $anthropic_configured,
		string $anthropic_model,
		bool $azure_chat_configured,
		string $azure_chat
	): ?string {
		if ( $anthropic_configured ) {
			return $anthropic_model;
		}

		if ( $azure_chat_configured ) {
			return $azure_chat;
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private static function available_abilities(
		bool $anthropic_configured,
		bool $azure_chat_configured,
		bool $azure_configured,
		bool $qdrant_configured,
		bool $cloudflare_configured
	): array {
		$abilities = [];

		self::maybe_add_ability( $abilities, 'flavor-agent/introspect-block', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/list-patterns', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/list-template-parts', 'edit_theme_options' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-theme-tokens', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/check-status', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-block', 'edit_posts', $anthropic_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-patterns', 'edit_posts', $azure_configured && $qdrant_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-template', 'edit_theme_options', $azure_chat_configured );
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
