<?php

declare(strict_types=1);

namespace FlavorAgent\AgentsAPI;

use FlavorAgent\AI\FeatureBootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the optional `flavor-agent` agent with Automattic's Agents API.
 *
 * Hooked to the upstream registration window and nothing else. When Agents API
 * is absent, unsupported, or the Flavor Agent feature gate is off, this
 * registers nothing and the rest of the plugin is unaffected.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class Registration {

	/**
	 * Fires after the agent definition is registered.
	 */
	public const REGISTERED_ACTION = 'flavor_agent_agents_api_agent_registered';

	/**
	 * Fires when registration was attempted but the runtime rejected it.
	 */
	public const FAILED_ACTION = 'flavor_agent_agents_api_agent_registration_failed';

	/**
	 * Filters whether a rejected registration is written to the error log.
	 */
	public const FAILURE_LOGGING_FILTER = 'flavor_agent_agents_api_registration_failure_logging_enabled';

	/**
	 * Register the agent definition.
	 *
	 * Callback for {@see Compatibility::REGISTRATION_HOOK}.
	 */
	public static function register(): void {
		if ( ! self::should_register() ) {
			return;
		}

		$tools = self::resolve_tools();

		if ( [] === $tools ) {
			// A definition with no tools is not a least-privilege agent, it is
			// a broken one: the abilities it mediates are not registered, so
			// there is nothing for the runtime to dispatch.
			self::handle_failure( 'no_registered_tools' );

			return;
		}

		try {
			$registered = \wp_register_agent(
				AgentDefinition::SLUG,
				AgentDefinition::registration_args( $tools )
			);
		} catch ( \Throwable $error ) {
			// `wp_agents_api_init` fires from `init`. A throwing pre-1.0
			// runtime must disable this adapter, not take the site down with
			// it. Upstream currently converts bad arguments into a null
			// return, so reaching here means the contract moved.
			self::handle_failure( 'registry_threw:' . \get_class( $error ) );

			return;
		}

		if ( null === $registered ) {
			self::handle_failure( 'registry_rejected_definition' );

			return;
		}

		\do_action( self::REGISTERED_ACTION, AgentDefinition::SLUG, $tools );
	}

	/**
	 * Whether every activation condition holds.
	 *
	 * Three independent gates, all required:
	 *
	 * 1. a supported Agents API runtime (see {@see Compatibility});
	 * 2. the canonical WordPress AI contracts the recommendation abilities are
	 *    built on — Agents API integration is deliberately *not* part of
	 *    {@see FeatureBootstrap::canonical_contracts_available()} itself, so a
	 *    Jetpack-only site keeps the existing non-agent runtime;
	 * 3. the Flavor Agent feature gate, because the recommendation abilities
	 *    this agent mediates only exist when it is on.
	 */
	public static function should_register(): bool {
		// Upstream `_doing_it_wrong()`s and returns null outside its
		// registration window. Never attempt it from another hook.
		if ( \function_exists( 'doing_action' ) && ! \doing_action( Compatibility::REGISTRATION_HOOK ) ) {
			return false;
		}

		if ( ! Compatibility::supported() ) {
			return false;
		}

		if ( ! FeatureBootstrap::canonical_contracts_available() ) {
			return false;
		}

		return FeatureBootstrap::recommendation_feature_enabled();
	}

	/**
	 * Allowlisted ability names that are actually registered on this site.
	 *
	 * Forbidden abilities are stripped after the allowlist filter, and every
	 * surviving name has to resolve to a registered ability. An ability that
	 * does not resolve would still be declared as a tool by the upstream
	 * runtime — with the bare name as its description and an empty parameter
	 * schema — and would fail only once the model called it.
	 *
	 * Ordering is safe despite both registration windows living on `init`:
	 * core fires `wp_abilities_api_init` lazily from
	 * `WP_Abilities_Registry::get_instance()`, which the first `wp_get_ability()`
	 * call below triggers. Flavor Agent's abilities are therefore registered
	 * before that call returns, whichever hook ran first.
	 *
	 * @return array<int, string>
	 */
	public static function resolve_tools(): array {
		$forbidden = AgentDefinition::deny_list();
		$resolved  = [];

		foreach ( AgentDefinition::tool_allowlist() as $ability_name ) {
			if ( \in_array( $ability_name, $forbidden, true ) ) {
				continue;
			}

			if ( ! self::ability_registered( $ability_name ) ) {
				continue;
			}

			$resolved[] = $ability_name;
		}

		return $resolved;
	}

	private static function ability_registered( string $ability_name ): bool {
		if ( ! \function_exists( 'wp_get_ability' ) ) {
			return false;
		}

		return null !== \wp_get_ability( $ability_name );
	}

	private static function handle_failure( string $reason ): void {
		\do_action( self::FAILED_ACTION, $reason, AgentDefinition::SLUG );

		if ( ! self::should_log_failure( $reason ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A silently absent agent is indistinguishable from an uninstalled runtime without a first-party diagnostic signal.
		\error_log(
			\sprintf(
				'[flavor-agent] Agents API agent registration failed: %s',
				$reason
			)
		);
	}

	private static function should_log_failure( string $reason ): bool {
		return (bool) \apply_filters( self::FAILURE_LOGGING_FILTER, true, $reason );
	}
}
