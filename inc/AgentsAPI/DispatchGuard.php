<?php

declare(strict_types=1);

namespace FlavorAgent\AgentsAPI;

use FlavorAgent\Abilities\Registration as AbilityRegistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses irreversible Flavor Agent mutations reached by model-chosen reflection.
 *
 * `agents/ability-call` invokes any registered ability by name. Its only guard
 * is a self-recursion check, so an agent run that can see it reaches every
 * ability on the site regardless of the calling agent's declared tool profile.
 * {@see AgentDefinition} denies it to the `flavor-agent` agent, but that deny
 * binds one agent definition; this guard binds the ability itself, so a run
 * driven by any *other* agent on the site is covered too.
 *
 * Scope is deliberately narrow, because "reached through the Abilities API" is
 * not by itself a threat — several Flavor Agent abilities are *designed* to be
 * called that way:
 *
 * - **`agents/ability-call` (guarded).** The target ability name is chosen by a
 *   model mid-conversation. Nothing about that choice was authored, reviewed,
 *   or declared in advance.
 * - **Workflow `ability` steps (not guarded).** `WP_Agent_Workflow_Runner`'s
 *   default handler dispatches an ability named in a workflow spec. Somebody
 *   wrote that name into the spec: it is a deliberate grant, the same class of
 *   act as adding a tool to an agent definition.
 * - **Declared agent tools (not guarded here).** Bounded by the agent's tool
 *   policy — {@see AgentDefinition::deny_list()} for this plugin's agent.
 * - **The MCP and REST external-apply lanes (not guarded).** The shipped,
 *   attested surface. Denying those would break a released feature.
 *
 * What is guarded is narrower still: only abilities whose own declared
 * annotations say `destructive`. The four `request-*-apply` abilities are not
 * destructive and are not guarded — queuing a pending row *is* the governed
 * loop working, and a site administrator still decides it in Settings > AI
 * Activity. `undo-activity` is the one that executes on the live site with no
 * second approval, and it is the one this guard exists for.
 *
 * The guard only ever narrows. A permission decision that already denied the
 * call is returned untouched; this never grants a capability.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class DispatchGuard {

	/**
	 * Upstream permission gate for the generic dispatch meta-ability.
	 *
	 * Filters `current_user_can( 'manage_options' )` with the call input, whose
	 * `name` key is the target ability. Pinned against real upstream source by
	 * {@see \FlavorAgent\Tests\AgentsApiUpstreamContractTest}.
	 */
	public const PERMISSION_FILTER = 'agents_ability_call_permission';

	/**
	 * Escape hatch for a site that deliberately wants a destructive Flavor Agent
	 * ability reachable through generic dispatch.
	 *
	 * Receives `( false, string $ability_name, array $input )` and must return
	 * strictly `true` to re-allow. Returning true re-opens an unreviewed live
	 * mutation to a model's choice of tool; it is not a default worth having.
	 */
	public const DECISION_FILTER = 'flavor_agent_agents_api_dispatch_allowed';

	/**
	 * Fires when a dispatch is refused. Receives `( string $ability_name, array $input )`.
	 */
	public const DENIED_ACTION = 'flavor_agent_agents_api_dispatch_denied';

	private const ABILITY_PREFIX = 'flavor-agent/';

	private static bool $registered = false;

	/**
	 * Attach the guard.
	 *
	 * Deliberately not gated on {@see Compatibility::supported()}. A site
	 * running an Agents API version this plugin does not otherwise integrate
	 * with still has `agents/ability-call`, and hardening that declines to
	 * apply itself on unsupported runtimes protects the wrong thing. When the
	 * Agents API is absent the filter simply never fires.
	 */
	public static function register(): void {
		if ( self::$registered || ! \function_exists( 'add_filter' ) ) {
			return;
		}

		self::$registered = true;

		\add_filter( self::PERMISSION_FILTER, [ self::class, 'filter_dispatch_permission' ], 10, 2 );
	}

	/**
	 * Deny generic dispatch of a destructive Flavor Agent ability.
	 *
	 * @param mixed $allowed Upstream permission decision.
	 * @param mixed $input   Call input; `name` is the target ability.
	 */
	public static function filter_dispatch_permission( mixed $allowed, mixed $input = null ): bool {
		// Only ever narrow. An upstream or third-party denial stands; a grant is
		// examined, never widened.
		if ( ! $allowed ) {
			return false;
		}

		$input = \is_array( $input ) ? $input : [];
		$name  = \is_string( $input['name'] ?? null ) ? \trim( $input['name'] ) : '';

		if ( ! self::is_guarded( $name ) ) {
			return true;
		}

		if ( true === \apply_filters( self::DECISION_FILTER, false, $name, $input ) ) {
			return true;
		}

		\do_action( self::DENIED_ACTION, $name, $input );

		return false;
	}

	/**
	 * Whether an ability name is refused through generic dispatch.
	 *
	 * Two sources, because neither alone is sufficient. The static list is
	 * derived from first-party registration data and answers correctly with no
	 * WordPress runtime at all; live annotation introspection catches an
	 * ability registered somewhere the static derivation cannot see — the plain
	 * abilities are registered inline in `Registration::register_abilities()`
	 * rather than through an enumerable class map.
	 *
	 * A name that resolves to no ability is not guarded: dispatch already fails
	 * it with `ability_not_found`, so there is nothing to refuse.
	 */
	public static function is_guarded( string $name ): bool {
		$name = \trim( $name );

		if ( '' === $name || ! \str_starts_with( $name, self::ABILITY_PREFIX ) ) {
			return false;
		}

		if ( \in_array( $name, self::guarded_abilities(), true ) ) {
			return true;
		}

		return self::declares_destructive( $name );
	}

	/**
	 * Read an ability's own `destructive` annotation from the live registry.
	 */
	private static function declares_destructive( string $name ): bool {
		if ( ! \function_exists( 'wp_get_ability' ) ) {
			return false;
		}

		$ability = \wp_get_ability( $name );

		if ( ! \is_object( $ability ) ) {
			return false;
		}

		$annotations = null;

		if ( \method_exists( $ability, 'get_meta_item' ) ) {
			$annotations = $ability->get_meta_item( 'annotations', [] );
		}

		if ( ! \is_array( $annotations ) && \method_exists( $ability, 'get_meta' ) ) {
			$meta        = $ability->get_meta();
			$annotations = \is_array( $meta ) && \is_array( $meta['annotations'] ?? null )
				? $meta['annotations']
				: [];
		}

		return \is_array( $annotations ) && ! empty( $annotations['destructive'] );
	}

	/**
	 * Flavor Agent abilities refused through generic dispatch.
	 *
	 * Derived from each ability's own declared annotations rather than listed by
	 * hand, so a newly destructive ability is guarded by construction.
	 * {@see \FlavorAgent\Tests\AgentsApiDispatchGuardTest} asserts that no other
	 * meta source in the plugin declares `destructive` without being covered.
	 *
	 * @return array<int, string>
	 */
	public static function guarded_abilities(): array {
		$guarded = [];

		foreach ( \array_keys( AbilityRegistration::external_apply_ability_classes() ) as $name ) {
			$meta = AbilityRegistration::external_apply_meta( (string) $name );

			if ( ! empty( $meta['annotations']['destructive'] ) ) {
				$guarded[] = (string) $name;
			}
		}

		return $guarded;
	}

	/**
	 * Test seam: forget that the filter was attached.
	 */
	public static function reset(): void {
		self::$registered = false;
	}
}
