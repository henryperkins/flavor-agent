<?php

declare(strict_types=1);

namespace FlavorAgent\Abilities;

use FlavorAgent\AI\FeatureBootstrap;
use FlavorAgent\Admin\Settings\Config;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Cloudflare\PatternSearchClient;
use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
use FlavorAgent\Context\ServerCollector;
use FlavorAgent\LLM\ChatClient;
use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\Retrieval\PatternRetrievalBackendFactory;

final class InfraAbilities {


	public static function get_theme_tokens( mixed $input ): array {
		unset( $input );

		return ServerCollector::for_tokens();
	}

	public static function get_active_theme( mixed $input ): array {
		unset( $input );

		return ServerCollector::for_active_theme();
	}

	public static function get_theme_presets( mixed $input ): array {
		unset( $input );

		return ServerCollector::for_theme_presets();
	}

	public static function get_theme_styles( mixed $input ): array {
		unset( $input );

		return ServerCollector::for_theme_styles();
	}

	public static function check_status( mixed $input ): array {
		unset( $input );

		$wordpress_ai_client_configured = WordPressAIClient::is_supported();
		$workers_ai_embedding_config    = Provider::embedding_configuration( WorkersAIEmbeddingConfiguration::PROVIDER );
		$qdrant_url                     = get_option( 'flavor_agent_qdrant_url', '' );
		$qdrant_key                     = get_option( 'flavor_agent_qdrant_key', '' );
		$cloudflare_ai_search_id        = AISearchClient::configured_instance_id();
		$pattern_backend                = PatternRetrievalBackendFactory::selected_backend();

		$qdrant_configured          = ! empty( $qdrant_url ) && ! empty( $qdrant_key );
		$cloudflare_configured      = AISearchClient::is_configured();
		$active_chat_configured     = ChatClient::is_supported();
		$recommendations_enabled    = FeatureBootstrap::recommendation_feature_enabled();
		$pattern_backend_configured = Config::PATTERN_BACKEND_CLOUDFLARE_AI_SEARCH === $pattern_backend
			? PatternSearchClient::is_configured()
			: ( Provider::embedding_configured() && $qdrant_configured );
		$patterns_configured        = $recommendations_enabled
			&& $active_chat_configured
			&& $pattern_backend_configured;
			$settings_url           = function_exists( 'admin_url' )
				? admin_url( 'options-general.php?page=flavor-agent' )
				: '';
			$connectors_url         = function_exists( 'admin_url' )
				? admin_url( 'options-connectors.php' )
				: '';

		$abilities = self::available_abilities(
			$recommendations_enabled && $active_chat_configured,
			$recommendations_enabled && $active_chat_configured,
			$patterns_configured,
			$cloudflare_configured
		);

		return [
			'configured'         => ( $recommendations_enabled && $active_chat_configured )
				|| $patterns_configured,
			'model'              => ( $recommendations_enabled && $active_chat_configured )
				? Provider::active_chat_model()
				: null,
			'availableAbilities' => $abilities,
			'surfaces'           => SurfaceCapabilities::build(
				$settings_url,
				$connectors_url
			),
			'backends'           => [
				'wordpress_ai_client'   => [
					'configured' => $wordpress_ai_client_configured,
				],
				'cloudflare_workers_ai' => [
					'configured'     => $workers_ai_embedding_config['configured'],
					'embeddingModel' => ! empty( $workers_ai_embedding_config['model'] )
						? $workers_ai_embedding_config['model']
						: null,
				],
				'qdrant'                => [
					'configured' => $qdrant_configured,
				],
				'cloudflare_ai_search'  => [
					'configured' => $cloudflare_configured,
					'instanceId' => $cloudflare_configured ? $cloudflare_ai_search_id : null,
				],
			],
		];
	}

	/**
	 * @return string[]
	 */
	private static function available_abilities(
		bool $block_recommendations_configured,
		bool $chat_configured,
		bool $patterns_configured,
		bool $cloudflare_configured
	): array {
		$abilities = [];

		self::maybe_add_ability( $abilities, 'flavor-agent/introspect-block', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-content', 'edit_posts', $chat_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/list-patterns', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-pattern', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/list-synced-patterns', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-synced-pattern', 'edit_posts' );
		if ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' ) ) {
			$abilities[] = 'flavor-agent/list-template-parts';
		}
		self::maybe_add_ability( $abilities, 'flavor-agent/list-allowed-blocks', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-active-theme', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-theme-presets', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-theme-styles', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/get-theme-tokens', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/check-status', 'edit_posts' );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-block', 'edit_posts', $block_recommendations_configured );
		self::maybe_add_ability( $abilities, 'flavor-agent/recommend-patterns', 'edit_posts', $patterns_configured );
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
