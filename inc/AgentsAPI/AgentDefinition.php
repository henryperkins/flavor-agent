<?php

declare(strict_types=1);

namespace FlavorAgent\AgentsAPI;

use FlavorAgent\Abilities\Registration as AbilityRegistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The declarative `flavor-agent` agent definition passed to `wp_register_agent()`.
 *
 * Phase 1 of the Agents API integration is deliberately read/recommend only:
 * the agent may collect context and produce governed recommendations, but it
 * has no tool that mutates the site and no tool that queues a pending apply.
 * Mutation stays behind Flavor Agent's own request-apply → admin decision →
 * executor → drift-checked undo state machine, which the agent runtime does
 * not participate in.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentDefinition {

	public const SLUG = 'flavor-agent';

	/**
	 * Filters the ability names exposed to the agent runtime as tools.
	 *
	 * Additions are still bounded: {@see self::FORBIDDEN_ABILITIES} is stripped
	 * after the filter runs, and every surviving name must resolve to a
	 * registered ability before it reaches the agent definition.
	 */
	public const TOOL_ALLOWLIST_FILTER = 'flavor_agent_agents_api_tool_allowlist';

	/**
	 * Maximum agent-loop turns. Conservative on purpose — upstream defaults to
	 * 12, and a recommendation flow needs a handful of reads plus one
	 * recommendation call, not an open-ended research loop.
	 */
	public const MAX_TURNS = 6;

	/**
	 * Read calls permitted after a read anchor before the run must commit to a
	 * recommendation. Enforced by the upstream runtime's tool-call gate, not by
	 * the system prompt, so the model cannot opt out of it.
	 */
	public const MAX_DISCOVERY_CALLS = 8;

	/**
	 * Status and context reads the agent needs to build a valid recommendation
	 * request. Each is an existing read-only ability with its own capability
	 * check; none of them writes.
	 *
	 * `recommend-template` and `recommend-template-part` take a `templateRef` /
	 * `templatePartRef` the agent cannot invent, so the corresponding list
	 * abilities are part of the least-privilege profile rather than an
	 * expansion of it — without them those recommendation tools are unusable
	 * through a conversational entry point.
	 *
	 * @var array<int, string>
	 */
	private const READ_ABILITIES = [
		'flavor-agent/check-status',
		'flavor-agent/get-active-theme',
		'flavor-agent/get-theme-tokens',
		'flavor-agent/list-templates',
		'flavor-agent/list-template-parts',
		'flavor-agent/list-patterns',
	];

	/**
	 * Activity reads. Bounded by their own permission callbacks: the scoped
	 * reads an agent is allowed to make, not the admin-global audit view.
	 *
	 * @var array<int, string>
	 */
	private const ACTIVITY_READ_ABILITIES = [
		'flavor-agent/get-activity',
		'flavor-agent/list-activity',
	];

	/**
	 * Abilities that must never be declared as tools in this phase, whatever a
	 * site filter asks for.
	 *
	 * The four `request-*-apply` abilities create pending review work and
	 * `undo-activity` reverses an executed change; both carry ordering,
	 * freshness, drift, and audit implications that Phase 2 has to prove
	 * end-to-end before a model can reach them.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_ABILITIES = [
		'flavor-agent/request-style-apply',
		'flavor-agent/request-template-apply',
		'flavor-agent/request-template-part-apply',
		'flavor-agent/request-post-blocks-apply',
		'flavor-agent/undo-activity',
	];

	/**
	 * Ability names the agent may call, before registration filtering.
	 *
	 * @return array<int, string>
	 */
	public static function tool_allowlist(): array {
		$allowlist = \array_merge(
			self::READ_ABILITIES,
			\array_keys( AbilityRegistration::preview_recommendation_ability_classes() ),
			\array_keys( AbilityRegistration::recommendation_ability_classes() ),
			self::ACTIVITY_READ_ABILITIES
		);

		if ( \function_exists( 'apply_filters' ) ) {
			$filtered = \apply_filters( self::TOOL_ALLOWLIST_FILTER, $allowlist );

			if ( \is_array( $filtered ) ) {
				$allowlist = $filtered;
			}
		}

		return self::normalize_ability_names( $allowlist );
	}

	/**
	 * Ability names that must never reach the agent runtime in this phase.
	 *
	 * @return array<int, string>
	 */
	public static function forbidden_abilities(): array {
		return self::FORBIDDEN_ABILITIES;
	}

	/**
	 * Registration arguments for `wp_register_agent()`.
	 *
	 * @param array<int, string> $tools Resolved, registered ability names.
	 * @return array<string, mixed>
	 */
	public static function registration_args( array $tools ): array {
		$tools = self::normalize_ability_names( $tools );

		return [
			'label'          => __( 'Flavor Agent', 'flavor-agent' ),
			'description'    => __( 'Governed WordPress change assistant. Reads live site context and returns schema-bounded recommendations for blocks, content, patterns, navigation, styles, templates, and template parts. Recommendations are advisory: applying one is a separate, review-gated Flavor Agent operation.', 'flavor-agent' ),
			'default_config' => [
				'instructions'    => self::instructions(),
				// `enabled_tools` is the canonical field the upstream runtime
				// reads; `tools` is an accepted alias. Only these names become
				// tool declarations, and each is dispatched back through the
				// Abilities API where its own permission callback runs.
				'enabled_tools'   => $tools,
				'max_turns'       => self::MAX_TURNS,
				// Second bound, enforced by the runtime's tool visibility
				// resolver rather than by the declaration list: allow-mode
				// keeps the profile closed even if another policy fragment
				// widens it, and the deny list removes mutation-capable
				// abilities unconditionally (deny is applied last).
				'tool_policy'     => [
					'mode'  => 'allow',
					'tools' => $tools,
					'deny'  => self::FORBIDDEN_ABILITIES,
				],
				'tool_call_rules' => self::tool_call_rules( $tools ),
			],
			'meta'           => self::provenance_meta(),
		];
	}

	/**
	 * Source provenance so diagnostics can attribute the registered agent.
	 *
	 * @return array<string, string>
	 */
	public static function provenance_meta(): array {
		return [
			'source_plugin'  => 'flavor-agent/flavor-agent.php',
			'source_type'    => 'bundled-agent',
			'source_package' => 'flavor-agent',
			'source_version' => \defined( 'FLAVOR_AGENT_VERSION' ) ? (string) \constant( 'FLAVOR_AGENT_VERSION' ) : '0.1.0',
		];
	}

	/**
	 * System instruction positioning the agent as a governed change assistant.
	 *
	 * Deliberately states what the tool profile already enforces. The prompt is
	 * not the control — the declaration list, tool policy, and each ability's
	 * permission callback are — but a model that understands the boundary
	 * produces fewer runs that end in a capability denial.
	 */
	public static function instructions(): string {
		return \implode(
			"\n",
			[
				'You are Flavor Agent, a governed change assistant for this WordPress site.',
				'',
				'You do not change the site. Your tools read live context and return schema-bounded recommendations for blocks, post content, patterns, navigation, styles, templates, and template parts. Nothing you produce is applied by calling a tool.',
				'',
				'Applying a recommendation is a separate Flavor Agent operation: it is validated against freshness signatures, queued as a pending row, decided by a site administrator in Settings > AI Activity, executed server-side, and reversible through a drift-checked undo. Describe that path when a user asks how to apply something. Never state or imply that you have changed the site, queued an approval, or undone anything.',
				'',
				'Read only the context a request needs. Prefer one recommendation call over repeated exploration: reads are budgeted and the run is turn-bounded.',
				'',
				'A tool that returns a permission error is not a tool to retry with different arguments. Report what was denied and stop.',
			]
		);
	}

	/**
	 * Declarative tool-call budget for the read/recommend loop.
	 *
	 * One bounded-discovery rule per read anchor: after any read, at most
	 * MAX_DISCOVERY_CALLS further reads may run before the run has to commit to
	 * a recommendation call. Completion is not gated — answering "which
	 * templates exist?" is a legitimate run that never needs a recommendation —
	 * so this is a rate limit on exploration, not a required-commit contract.
	 *
	 * @param array<int, string> $tools Resolved ability names.
	 * @return array<int, array<string, mixed>>
	 */
	private static function tool_call_rules( array $tools ): array {
		$reads = \array_values(
			\array_intersect(
				$tools,
				\array_merge( self::READ_ABILITIES, self::ACTIVITY_READ_ABILITIES )
			)
		);

		$recommendations = \array_values(
			\array_intersect(
				$tools,
				\array_keys( AbilityRegistration::recommendation_ability_classes() )
			)
		);

		if ( [] === $reads || [] === $recommendations ) {
			return [];
		}

		$rules = [];

		foreach ( $reads as $anchor ) {
			$rules[] = [
				'id'              => 'flavor-agent-bounded-discovery-' . \str_replace( '/', '-', $anchor ),
				'after_tool'      => $anchor,
				'limited_tools'   => $reads,
				'max_calls'       => self::MAX_DISCOVERY_CALLS,
				'require_one_of'  => $recommendations,
				'gate_calls'      => true,
				'gate_completion' => false,
			];
		}

		return $rules;
	}

	/**
	 * Trim, de-duplicate, and drop non-string ability names.
	 *
	 * @param array<int|string, mixed> $names
	 * @return array<int, string>
	 */
	private static function normalize_ability_names( array $names ): array {
		$normalized = [];

		foreach ( $names as $name ) {
			if ( ! \is_string( $name ) ) {
				continue;
			}

			$name = \trim( $name );

			if ( '' === $name || \in_array( $name, $normalized, true ) ) {
				continue;
			}

			$normalized[] = $name;
		}

		return $normalized;
	}
}
