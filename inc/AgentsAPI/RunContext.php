<?php

declare(strict_types=1);

namespace FlavorAgent\AgentsAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redaction-safe bridge from an Agents API run to Flavor Agent attribution.
 *
 * The upstream runtime dispatches tool calls through the Abilities API without
 * handing the ability its run context, so an ability cannot otherwise tell that
 * it was invoked by an agent. This observer captures the three identifiers that
 * are safe to persist — agent slug, run id, session id — from the runtime's
 * pre-tool-call seam, and Flavor Agent's own request attribution reads them
 * back when it builds `requestMeta`.
 *
 * Three boundaries are deliberate:
 *
 * - It observes only. The pre-tool-call filter can reject or replace a tool
 *   call; this callback returns the decision it was given, unchanged.
 * - It captures only opaque identifiers. Bearer tokens, the execution
 *   principal, caller context, tool parameters, and transcript content are
 *   never read and never stored.
 * - Values are normalized and length-bounded before they can reach an activity
 *   row, so an agent-supplied session id cannot become an unbounded write.
 *
 * An Agents API session is not a Flavor Agent activity record and a run id is
 * not a stale-response request token; these identifiers correlate the two
 * systems, they do not merge them.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class RunContext {

	/**
	 * Upstream per-tool-call mediation seam. Fires inside the canonical
	 * conversation loop, so it covers any runtime driving that loop rather
	 * than only the default chat handler.
	 */
	public const PRE_TOOL_CALL_FILTER = 'agents_api_pre_tool_call_decision';

	private const MAX_VALUE_BYTES = 128;

	/**
	 * @var array<string, string>
	 */
	private static array $current = [];

	/**
	 * Ability the captured context belongs to.
	 *
	 * The seam is a *pre*-call hook and upstream exposes no matching post-call
	 * hook, so nothing signals that a tool call finished. Without a binding, a
	 * later recommendation in the same PHP process — a long-lived WP-CLI run,
	 * or a workflow that dispatches an ability step after a chat step — would
	 * inherit the finished run's identifiers and write them onto its activity
	 * row. Attribution that is confidently wrong is worse than absent.
	 *
	 * Two bounds replace the missing lifecycle signal: the context is only
	 * attached to the ability the runtime said it was about to dispatch, and
	 * {@see self::consume()} clears it on read, so one pre-call hook yields at
	 * most one attribution.
	 */
	private static string $current_ability = '';

	private static bool $registered = false;

	/**
	 * Attach the observer. Safe to call when Agents API is absent — the filter
	 * simply never fires.
	 */
	public static function register(): void {
		if ( self::$registered || ! \function_exists( 'add_filter' ) ) {
			return;
		}

		self::$registered = true;

		\add_filter( self::PRE_TOOL_CALL_FILTER, [ self::class, 'observe' ], 10, 2 );
	}

	/**
	 * Record the run identifiers for the tool call about to execute.
	 *
	 * @param mixed                $decision Upstream mediation decision.
	 * @param array<string, mixed> $context  Upstream mediation context.
	 * @return mixed The decision, unchanged.
	 */
	public static function observe( mixed $decision, mixed $context = null ): mixed {
		if ( ! \is_array( $context ) ) {
			return $decision;
		}

		$turn_context = \is_array( $context['turn_context'] ?? null ) ? $context['turn_context'] : [];

		$captured = [];

		foreach (
			[
				'agentSlug' => 'agent_slug',
				'runId'     => 'run_id',
				'sessionId' => 'session_id',
			] as $key => $source_key
		) {
			$value = self::normalize_identifier( $turn_context[ $source_key ] ?? null );

			if ( '' !== $value ) {
				$captured[ $key ] = $value;
			}
		}

		self::$current         = $captured;
		self::$current_ability = [] === $captured ? '' : self::target_ability( $context );

		return $decision;
	}

	/**
	 * Ability the runtime is about to dispatch for this tool call.
	 *
	 * Mirrors `WP_Agent_Ability_Tool_Executor::ability_name()`: a tool
	 * declaration may carry an `ability` / `ability_name` when the model-facing
	 * tool name differs from the registered ability, so the model-facing name is
	 * the last resort rather than the first. Pinned against upstream by
	 * {@see \FlavorAgent\Tests\AgentsApiUpstreamContractTest}.
	 *
	 * @param array<string, mixed> $context Upstream mediation context.
	 */
	private static function target_ability( array $context ): string {
		$declaration = \is_array( $context['tool_declaration'] ?? null ) ? $context['tool_declaration'] : [];
		$tool_call   = \is_array( $context['prepared_tool_call'] ?? null )
			? $context['prepared_tool_call']
			: ( \is_array( $context['raw_tool_call'] ?? null ) ? $context['raw_tool_call'] : [] );
		$metadata    = \is_array( $tool_call['metadata'] ?? null ) ? $tool_call['metadata'] : [];

		foreach (
			[
				$declaration['ability'] ?? null,
				$declaration['ability_name'] ?? null,
				$metadata['ability_name'] ?? null,
				$context['tool_name'] ?? null,
			] as $candidate
		) {
			if ( \is_string( $candidate ) && '' !== \trim( $candidate ) ) {
				return \trim( $candidate );
			}
		}

		return '';
	}

	public static function is_active(): bool {
		return [] !== self::$current;
	}

	/**
	 * Redaction-safe agent run identifiers for the current tool call.
	 *
	 * @return array<string, string> Empty when the request did not originate
	 *                               from an Agents API run.
	 */
	public static function current(): array {
		return self::$current;
	}

	/**
	 * Take the run identifiers for one ability dispatch, clearing them.
	 *
	 * Returns an empty array unless a run is active *and* `$ability_name` is the
	 * ability the runtime said it was dispatching. Both conditions matter: the
	 * first stops attribution outliving the process's last agent turn, the
	 * second stops a finished turn's identifiers landing on an unrelated
	 * recommendation that happens to run next in the same process.
	 *
	 * @return array<string, string>
	 */
	public static function consume( string $ability_name ): array {
		if ( [] === self::$current || \trim( $ability_name ) !== self::$current_ability ) {
			return [];
		}

		$captured = self::$current;

		self::reset();

		return $captured;
	}

	/**
	 * Ability the current captured context is bound to.
	 */
	public static function current_ability(): string {
		return self::$current_ability;
	}

	/**
	 * Clear the captured context. Used by tests and by any caller that needs to
	 * guarantee a clean attribution boundary within one process.
	 */
	public static function reset(): void {
		self::$current         = [];
		self::$current_ability = '';
	}

	/**
	 * Reduce a value to a bounded, log-safe opaque identifier.
	 *
	 * Run and session ids are runtime-generated strings, but they arrive from
	 * request input on resumed sessions, so they are treated as untrusted.
	 */
	private static function normalize_identifier( mixed $value ): string {
		if ( ! \is_string( $value ) ) {
			return '';
		}

		$value = \trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$value = \preg_replace( '/[^A-Za-z0-9_.:\-]/', '', $value );

		if ( ! \is_string( $value ) || '' === $value ) {
			return '';
		}

		return \substr( $value, 0, self::MAX_VALUE_BYTES );
	}
}
