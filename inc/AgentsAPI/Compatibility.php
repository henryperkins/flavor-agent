<?php

declare(strict_types=1);

namespace FlavorAgent\AgentsAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feature detection and version gate for Automattic's Agents API substrate.
 *
 * Agents API is an optional companion plugin, never a hard dependency: Flavor
 * Agent's editor UI, Abilities API surfaces, REST routes, and MCP server all
 * behave identically when it is absent. This class is the single activation
 * boundary for the adapter, and it fails closed — a missing symbol, a missing
 * contract constant, or a version we have not tested against all resolve to
 * "not supported" rather than a best-effort registration attempt.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class Compatibility {

	/**
	 * Minimum Agents API release this adapter is tested against.
	 *
	 * Pinned deliberately: the upstream project is pre-1.0 and `main` may be
	 * ahead of its latest tag, so the registration keys and tool-policy
	 * semantics we depend on are treated as versioned API. Raise this only
	 * alongside a re-read of the upstream registration and tool-policy
	 * contracts plus a passing contract-test run.
	 */
	public const MINIMUM_VERSION = '0.7.0';

	/**
	 * Upstream registration window. Agents may only be registered here.
	 */
	public const REGISTRATION_HOOK = 'wp_agents_api_init';

	/**
	 * Filters the detected Agents API version.
	 *
	 * Lets an integration environment pin the version explicitly when the
	 * runtime is loaded through Composer rather than as a plugin.
	 */
	public const VERSION_FILTER = 'flavor_agent_agents_api_version';

	/**
	 * Symbols the adapter depends on. Every one must exist before we register.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FUNCTIONS = [
		'wp_register_agent',
		'wp_get_agent',
	];

	/**
	 * @var array<int, string>
	 */
	private const REQUIRED_CLASSES = [
		'WP_Agent',
		'WP_Agents_Registry',
		'WP_Agent_Tool_Policy',
	];

	/**
	 * Whether the Agents API runtime is loaded at all.
	 *
	 * True for both delivery shapes upstream supports: the plugin form and the
	 * Composer `files` autoloader, which run the same bootstrap.
	 */
	public static function runtime_loaded(): bool {
		return \defined( 'AGENTS_API_LOADED' )
			|| \function_exists( 'wp_register_agent' );
	}

	/**
	 * Whether every symbol and contract constant the adapter binds to exists.
	 *
	 * This is the "fail closed when a required method, hook, or schema is
	 * missing" check: a version string alone cannot prove the runtime still
	 * exposes the seams we compiled against.
	 */
	public static function contracts_available(): bool {
		return '' === self::missing_contract();
	}

	/**
	 * First missing contract symbol, or an empty string when all are present.
	 */
	public static function missing_contract(): string {
		foreach ( self::REQUIRED_FUNCTIONS as $function_name ) {
			if ( ! \function_exists( $function_name ) ) {
				return 'function:' . $function_name;
			}
		}

		foreach ( self::REQUIRED_CLASSES as $class_name ) {
			if ( ! \class_exists( $class_name ) ) {
				return 'class:' . $class_name;
			}
		}

		// The registry owns the registration window we hook; without it,
		// `wp_register_agent()` cannot collect a definition.
		if ( ! \method_exists( 'WP_Agents_Registry', 'get_instance' ) ) {
			return 'method:WP_Agents_Registry::get_instance';
		}

		// Tool visibility is enforced from the agent's `default_config`
		// `tool_policy` fragment. Allow-mode is the semantic the allowlist
		// depends on, so a runtime without it must not be trusted to bound
		// the tool profile.
		if ( ! \defined( 'WP_Agent_Tool_Policy::MODE_ALLOW' ) ) {
			return 'const:WP_Agent_Tool_Policy::MODE_ALLOW';
		}

		// Tool declarations are built from the agent's declared ability names
		// and dispatched back through the Abilities API. Without a resolvable
		// ability registry there is nothing to mediate.
		if ( ! \function_exists( 'wp_get_ability' ) ) {
			return 'function:wp_get_ability';
		}

		return '';
	}

	/**
	 * Installed Agents API version, or an empty string when undetectable.
	 *
	 * Upstream defines no version constant, so the plugin header on the
	 * bootstrap file is the source of truth. That file is the same entry point
	 * under both the plugin and Composer install paths.
	 */
	public static function detected_version(): string {
		$version = '';

		if ( \defined( 'AGENTS_API_PLUGIN_FILE' ) && \function_exists( 'get_file_data' ) ) {
			$plugin_file = (string) \constant( 'AGENTS_API_PLUGIN_FILE' );

			if ( '' !== $plugin_file && \is_readable( $plugin_file ) ) {
				$data    = \get_file_data( $plugin_file, [ 'Version' => 'Version' ], 'plugin' );
				$version = \is_array( $data ) && \is_string( $data['Version'] ?? null )
					? \trim( $data['Version'] )
					: '';
			}
		}

		if ( \function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( self::VERSION_FILTER, $version );
			$version  = \is_string( $filtered ) ? \trim( $filtered ) : $version;
		}

		return $version;
	}

	/**
	 * Whether the detected version satisfies the pinned minimum.
	 *
	 * An undetectable version is unsupported on purpose: the adapter is bound
	 * to a pinned release, and silently adapting to an unknown build is the
	 * failure mode this gate exists to prevent.
	 */
	public static function version_supported(): bool {
		return self::meets_minimum( self::detected_version() );
	}

	/**
	 * Shared by {@see self::version_supported()} and {@see self::status()}, which
	 * needs the version string for reporting anyway. A helper rather than
	 * having `status()` call `version_supported()`, because that would re-read
	 * the plugin header for an answer it already has the input for.
	 */
	private static function meets_minimum( string $version ): bool {
		if ( '' === $version ) {
			return false;
		}

		return \version_compare( $version, self::MINIMUM_VERSION, '>=' );
	}

	/**
	 * Whether the adapter may register anything at all.
	 */
	public static function supported(): bool {
		return self::runtime_loaded()
			&& self::contracts_available()
			&& self::version_supported();
	}

	/**
	 * Structured agent-runtime readiness, reported separately from
	 * recommendation/backend readiness so operators can tell "no agent
	 * runtime" apart from "no configured provider".
	 *
	 * @return array{
	 *     present: bool,
	 *     supported: bool,
	 *     version: string,
	 *     minimumVersion: string,
	 *     reason: string,
	 *     missingContract: string
	 * }
	 */
	public static function status(): array {
		$present          = self::runtime_loaded();
		$missing_contract = $present ? self::missing_contract() : '';
		$version          = $present ? self::detected_version() : '';
		$supported        = false;
		$reason           = '';

		if ( ! $present ) {
			$reason = 'runtime_absent';
		} elseif ( '' !== $missing_contract ) {
			$reason = 'contract_missing';
		} elseif ( '' === $version ) {
			$reason = 'version_undetectable';
		} elseif ( ! self::meets_minimum( $version ) ) {
			$reason = 'version_unsupported';
		} else {
			$supported = true;
			$reason    = 'ok';
		}

		return [
			'present'         => $present,
			'supported'       => $supported,
			'version'         => $version,
			'minimumVersion'  => self::MINIMUM_VERSION,
			'reason'          => $reason,
			'missingContract' => $missing_contract,
		];
	}
}
