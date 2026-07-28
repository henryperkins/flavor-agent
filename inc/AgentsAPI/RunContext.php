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

		self::$current = $captured;

		return $decision;
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
	 * Clear the captured context. Used by tests and by any caller that needs to
	 * guarantee a clean attribution boundary within one process.
	 */
	public static function reset(): void {
		self::$current = [];
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
