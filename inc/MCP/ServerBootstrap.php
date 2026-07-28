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

	/**
	 * Read helpers the dedicated server needs to be self-sufficient.
	 *
	 * Without these, twelve of the fifteen curated tools cannot be called by a
	 * client connected only to `/wp-json/mcp/flavor-agent`, because their
	 * required inputs are opaque refs the client has no way to enumerate:
	 *
	 * - `list-templates`      -> `templateRef` for recommend-template and
	 *                            request-template-apply
	 * - `list-template-parts` -> `templatePartRef` / `templatePartId`
	 * - `get-theme-styles`    -> `scope` + `styleContext` for recommend-style
	 *                            and request-style-apply, and the
	 *                            `global_styles:<id>` scopeKey list-activity
	 *                            requires
	 * - `list-patterns`       -> `visiblePatternNames`
	 *
	 * This is an ergonomics fix, not a capability change. All four are already
	 * `show_in_rest` and `meta.mcp.public`, so the same principal can reach the
	 * same data today over the co-present universal default server — whose
	 * transport gate (`is_user_logged_in()`) is looser than this server's
	 * `edit_posts || edit_theme_options`. What changes is that the governed
	 * apply loop no longer requires a second MCP session and a round trip
	 * through the `execute-ability` meta-tool.
	 *
	 * The one case where they are load-bearing rather than convenient: a site
	 * that disables the universal default server via
	 * `mcp_adapter_create_default_server` otherwise has no MCP path to them at
	 * all.
	 *
	 * Deliberately NOT added: the three non-public helpers (`check-status`,
	 * `list-synced-patterns`, `get-synced-pattern`) — adding those would
	 * reverse a recorded non-publicity decision — and helpers whose data the
	 * server already collects itself (`get-theme-tokens`, `get-theme-presets`,
	 * `introspect-block`).
	 *
	 * @see docs/reference/abilities-and-routes.md
	 *
	 * @var array<int, string>
	 */
	private const DISCOVERY_READ_ABILITIES = [
		'flavor-agent/list-templates',
		'flavor-agent/list-template-parts',
		'flavor-agent/list-patterns',
		'flavor-agent/get-theme-styles',
	];

	/**
	 * Ability names exposed as first-class tools on the dedicated server.
	 *
	 * @return array<int, string>
	 */
	public static function server_ability_names(): array {
		return \array_values(
			\array_unique(
				\array_merge(
					\array_keys( Registration::recommendation_ability_classes() ),
					\array_keys( Registration::external_apply_ability_classes() ),
					self::DISCOVERY_READ_ABILITIES
				)
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	public static function discovery_read_abilities(): array {
		return self::DISCOVERY_READ_ABILITIES;
	}

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
			__( 'Governed AI changes for WordPress: schema-validated operations with freshness signatures, review-gated structural changes, server-side attribution, and drift-checked undo. Tools cover block, content, navigation, pattern, style, template, template-part, and post-content-structure recommendations plus the governed style, template, template-part, and post-blocks apply loops (request a pending apply, read its decision state, undo executed rows). Approval stays with site administrators in Settings > AI Activity. Read-only discovery tools (list templates, list template parts, list patterns, get theme styles) supply the refs and style context the recommend and apply tools require, so this server is usable without a second MCP connection.', 'flavor-agent' ),
			self::plugin_version(),
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			self::server_ability_names(),
			[],
			[],
			[ self::class, 'can_access_transport' ]
		);

		if ( \is_wp_error( $result ) ) {
			self::handle_registration_failure( $result );
		}
	}

	public static function can_access_transport( mixed $request = null ): bool {
		unset( $request );

		return \current_user_can( 'edit_posts' ) || \current_user_can( 'edit_theme_options' );
	}

	private static function handle_registration_failure( \WP_Error $result ): void {
		\do_action( 'flavor_agent_mcp_server_registration_failed', $result );

		if ( ! self::should_log_registration_failure( $result ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- MCP registration failures otherwise make the server unavailable with no first-party diagnostic signal.
		\error_log(
			\sprintf(
				'[flavor-agent] MCP server registration failed: %s - %s',
				$result->get_error_code(),
				$result->get_error_message()
			)
		);
	}

	private static function should_log_registration_failure( \WP_Error $result ): bool {
		return (bool) \apply_filters(
			'flavor_agent_mcp_server_registration_failure_logging_enabled',
			true,
			$result
		);
	}

	private static function plugin_version(): string {
		return \defined( 'FLAVOR_AGENT_VERSION' ) ? FLAVOR_AGENT_VERSION : '0.1.0';
	}
}
