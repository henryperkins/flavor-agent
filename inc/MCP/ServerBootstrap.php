<?php

declare(strict_types=1);

namespace FlavorAgent\MCP;

use FlavorAgent\Abilities\Registration;
use FlavorAgent\AI\FeatureBootstrap;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

final class ServerBootstrap {

	public static function register( ?object $adapter = null ): void {
		if ( ! FeatureBootstrap::canonical_contracts_available() || ! FeatureBootstrap::recommendation_feature_enabled() ) {
			return;
		}

		if ( null === $adapter ) {
			if ( ! \class_exists( McpAdapter::class ) ) {
				return;
			}

			$adapter = McpAdapter::instance();
		}

		if ( ! \method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$result = $adapter->create_server(
			'flavor-agent',
			'mcp',
			'flavor-agent',
			__( 'Flavor Agent', 'flavor-agent' ),
			__( 'AI-assisted WordPress recommendations across blocks, content, navigation, patterns, styles, templates, and template parts.', 'flavor-agent' ),
			self::plugin_version(),
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			\array_keys( Registration::recommendation_ability_classes() ),
			[],
			[],
			[ self::class, 'can_access_transport' ]
		);

		if ( \is_wp_error( $result ) ) {
			\error_log(
				\sprintf(
					'[flavor-agent] MCP server registration failed: %s - %s',
					$result->get_error_code(),
					$result->get_error_message()
				)
			);
		}
	}

	public static function can_access_transport( mixed $request = null ): bool {
		unset( $request );

		return \current_user_can( 'edit_posts' ) || \current_user_can( 'edit_theme_options' );
	}

	private static function plugin_version(): string {
		return \defined( 'FLAVOR_AGENT_VERSION' ) ? FLAVOR_AGENT_VERSION : '0.1.0';
	}
}
