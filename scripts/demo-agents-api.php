<?php
/**
 * Evidence harness for the Flavor Agent <-> Agents API integration.
 *
 * Answers one question with observations rather than assertions in a test
 * suite: on THIS site, right now, is the Agents API actually driving Flavor
 * Agent, and does the Phase 1 boundary hold?
 *
 * Run:
 *   wp eval-file scripts/demo-agents-api.php
 *
 * Tiers:
 *   A  wiring     - is the agent registered, with the tool profile we think?
 *   C  boundary   - are the mutation paths actually refused?
 *   B  live run   - a real agents/chat turn, correlated to activity rows.
 *
 * A and C are free and deterministic and run by default. B makes a real
 * provider call against whatever core Connector the site has configured, so it
 * is opt-in:
 *
 *   FA_DEMO_TIERS=A,B,C wp eval-file scripts/demo-agents-api.php
 *
 * Other environment overrides:
 *   FA_DEMO_PROMPT   user message for the tier B turn
 *   FA_DEMO_JSON=1   suppress the human-readable log, emit only the JSON line
 *
 * The last stdout line is always DEMO_RESULT={...}.
 *
 * Dev-only: `scripts/` is `.distignore`'d and never ships.
 *
 * @package FlavorAgent
 */

declare(strict_types=1);

namespace FlavorAgent\Demo;

use FlavorAgent\Abilities\Registration as AbilityRegistration;
use FlavorAgent\AgentsAPI\AgentDefinition;
use FlavorAgent\AgentsAPI\Compatibility;
use FlavorAgent\AgentsAPI\DispatchGuard;
use FlavorAgent\Activity\Repository;
use FlavorAgent\Activity\RequestLoggingBridge;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside WordPress: wp eval-file scripts/demo-agents-api.php\n" );
	exit( 1 );
}

const PASS         = 'pass';
const FAIL         = 'fail';
const SKIP         = 'skip';
const INCONCLUSIVE = 'inconclusive';

/**
 * Overall-only: nothing failed, but the run did not gather the evidence it was
 * asked for. Never a per-check status.
 */
const INCOMPLETE = 'incomplete';

$quiet   = '' !== (string) getenv( 'FA_DEMO_JSON' );
$tiers   = parse_tiers( (string) getenv( 'FA_DEMO_TIERS' ) );
$results = [];

/**
 * Record and print one observation.
 *
 * @param array<int, array<string, mixed>> $results Result accumulator.
 * @param array<string, mixed>             $detail  Structured evidence.
 */
function observe( array &$results, bool $quiet, string $id, string $label, string $status, array $detail = [] ): void {
	$results[] = [
		'id'     => $id,
		'label'  => $label,
		'status' => $status,
		'detail' => $detail,
	];

	if ( $quiet ) {
		return;
	}

	$marker = [
		PASS         => 'PASS',
		FAIL         => 'FAIL',
		SKIP         => 'SKIP',
		INCONCLUSIVE => '????',
	][ $status ] ?? '????';

	printf( "  [%s] %-4s %s\n", $id, $marker, $label );

	foreach ( $detail as $key => $value ) {
		printf( "             %s: %s\n", $key, summarize( $value ) );
	}
}

/**
 * @param mixed $value Any evidence value.
 */
function summarize( $value ): string {
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}

	if ( is_scalar( $value ) || null === $value ) {
		return (string) $value;
	}

	$encoded = wp_json_encode( $value );

	if ( ! is_string( $encoded ) ) {
		return '(unencodable)';
	}

	return strlen( $encoded ) > 400 ? substr( $encoded, 0, 397 ) . '...' : $encoded;
}

/**
 * @return array<int, string>
 */
function parse_tiers( string $raw ): array {
	if ( '' === trim( $raw ) ) {
		return [ 'A', 'C' ];
	}

	$tiers = array_values(
		array_intersect(
			array_map( 'strtoupper', array_map( 'trim', explode( ',', $raw ) ) ),
			[ 'A', 'B', 'C' ]
		)
	);

	return [] === $tiers ? [ 'A', 'C' ] : $tiers;
}

/**
 * The registered agent object, or null.
 */
function registered_agent(): ?object {
	if ( ! function_exists( 'wp_get_agent' ) ) {
		return null;
	}

	$agent = wp_get_agent( AgentDefinition::SLUG );

	return is_object( $agent ) ? $agent : null;
}

/**
 * @return array<string, mixed>
 */
function agent_config(): array {
	$agent = registered_agent();

	if ( null === $agent || ! method_exists( $agent, 'get_default_config' ) ) {
		return [];
	}

	$config = $agent->get_default_config();

	return is_array( $config ) ? $config : [];
}

if ( ! $quiet ) {
	printf(
		"\nFlavor Agent x Agents API - evidence harness\nsite: %s\ntiers: %s\n\n",
		home_url(),
		implode( ',', $tiers )
	);
}

// ---------------------------------------------------------------- Tier A ----
if ( in_array( 'A', $tiers, true ) ) {
	if ( ! $quiet ) {
		echo "Tier A - wiring\n";
	}

	$status = Compatibility::status();

	observe(
		$results,
		$quiet,
		'A1',
		'Agents API runtime present and supported',
		! empty( $status['supported'] ) ? PASS : FAIL,
		[
			'version'         => $status['version'] ?? '(undetected)',
			'minimumVersion'  => $status['minimumVersion'] ?? '',
			'reason'          => $status['reason'] ?? '',
			'missingContract' => $status['missingContract'] ?? '',
		]
	);

	$agent = registered_agent();

	observe(
		$results,
		$quiet,
		'A2',
		'The flavor-agent agent is registered with the runtime',
		null !== $agent ? PASS : FAIL,
		[ 'slug' => AgentDefinition::SLUG ]
	);

	$config = agent_config();
	$tools  = is_array( $config['enabled_tools'] ?? null ) ? $config['enabled_tools'] : [];

	observe(
		$results,
		$quiet,
		'A3',
		'The agent declares a non-empty read/recommend tool profile',
		[] !== $tools ? PASS : FAIL,
		[
			'toolCount' => count( $tools ),
			'tools'     => $tools,
			'maxTurns'  => $config['max_turns'] ?? null,
		]
	);

	$deny    = is_array( $config['tool_policy']['deny'] ?? null ) ? $config['tool_policy']['deny'] : [];
	$missing = array_values( array_diff( AgentDefinition::deny_list(), $deny ) );

	observe(
		$results,
		$quiet,
		'A4',
		'The runtime kept the full deny list, including generic dispatch',
		[] === $missing && [] !== $deny ? PASS : FAIL,
		[
			'deny'    => $deny,
			'missing' => $missing,
		]
	);

	$guard_attached = false !== has_filter(
		DispatchGuard::PERMISSION_FILTER,
		[ DispatchGuard::class, 'filter_dispatch_permission' ]
	);

	observe(
		$results,
		$quiet,
		'A5',
		'The site-wide dispatch guard is attached',
		$guard_attached ? PASS : FAIL,
		[
			'filter'  => DispatchGuard::PERMISSION_FILTER,
			'guarded' => DispatchGuard::guarded_abilities(),
		]
	);

	$check   = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'flavor-agent/check-status' ) : null;
	$runtime = null;

	if ( is_object( $check ) && method_exists( $check, 'execute' ) ) {
		$reported = $check->execute( [] );
		$runtime  = is_array( $reported ) ? ( $reported['agentRuntime'] ?? null ) : null;
	}

	observe(
		$results,
		$quiet,
		'A6',
		'check-status reports the agent runtime to callers',
		is_array( $runtime ) && ! empty( $runtime['supported'] ) ? PASS : FAIL,
		[ 'agentRuntime' => $runtime ]
	);
}

// ---------------------------------------------------------------- Tier C ----
if ( in_array( 'C', $tiers, true ) ) {
	if ( ! $quiet ) {
		echo "\nTier C - boundary\n";
	}

	// A true here is what upstream's own manage_options gate would produce for
	// an administrator. The question is what Flavor Agent does with it.
	$undo_allowed = (bool) apply_filters(
		DispatchGuard::PERMISSION_FILTER,
		true,
		[ 'name' => 'flavor-agent/undo-activity' ]
	);

	observe(
		$results,
		$quiet,
		'C1',
		'Generic dispatch of undo-activity is refused',
		$undo_allowed ? FAIL : PASS,
		[ 'allowed' => $undo_allowed ]
	);

	$request_allowed = (bool) apply_filters(
		DispatchGuard::PERMISSION_FILTER,
		true,
		[ 'name' => 'flavor-agent/request-style-apply' ]
	);

	observe(
		$results,
		$quiet,
		'C2',
		'The guard is scoped: review-gated apply requests stay reachable',
		$request_allowed ? PASS : FAIL,
		[ 'allowed' => $request_allowed ]
	);

	$config    = agent_config();
	$tools     = is_array( $config['enabled_tools'] ?? null ) ? $config['enabled_tools'] : [];
	$forbidden = array_values( array_intersect( $tools, AgentDefinition::deny_list() ) );

	observe(
		$results,
		$quiet,
		'C3',
		'No mutation or dispatch tool reached the agent profile',
		[] === $forbidden ? PASS : FAIL,
		[ 'leaked' => $forbidden ]
	);

	$dispatch_registered = function_exists( 'wp_get_ability' ) && is_object( wp_get_ability( 'agents/ability-call' ) );

	observe(
		$results,
		$quiet,
		'C4',
		'agents/ability-call exists on this site but is denied to this agent',
		$dispatch_registered && ! in_array( 'agents/ability-call', $tools, true ) ? PASS : INCONCLUSIVE,
		[
			'registered' => $dispatch_registered,
			'inProfile'  => in_array( 'agents/ability-call', $tools, true ),
		]
	);
}

// ---------------------------------------------------------------- Tier B ----
if ( in_array( 'B', $tiers, true ) ) {
	if ( ! $quiet ) {
		echo "\nTier B - live run\n";
	}

	$chat = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'agents/chat' ) : null;

	if ( ! is_object( $chat ) || ! method_exists( $chat, 'execute' ) ) {
		observe( $results, $quiet, 'B1', 'agents/chat is available', SKIP, [ 'reason' => 'agents/chat is not registered' ] );
	} else {
		$prompt = (string) getenv( 'FA_DEMO_PROMPT' );

		if ( '' === trim( $prompt ) ) {
			// Must drive the full read -> recommend loop, not just a read.
			// Read-only tools persist no activity row, so a prompt that stops
			// at enumeration leaves B3 with nothing to correlate and the run
			// can never satisfy the exit gate it exists to test. This mirrors
			// the agent's own tool_call_rules: discover, then commit to one
			// recommendation.
			$prompt = 'List this site\'s templates, choose one, and recommend a concrete improvement to it. '
				. 'Use your tools for both steps.';
		}

		$before = microtime( true );

		$reply = $chat->execute(
			[
				'agent'          => AgentDefinition::SLUG,
				'message'        => $prompt,
				'client_context' => [
					'source'      => 'rest',
					'client_name' => 'flavor-agent-demo',
				],
			]
		);

		$elapsed = round( microtime( true ) - $before, 2 );

		if ( is_wp_error( $reply ) ) {
			observe(
				$results,
				$quiet,
				'B1',
				'A real agents/chat turn completed',
				FAIL,
				[
					'code'    => $reply->get_error_code(),
					'message' => $reply->get_error_message(),
					'hint'    => 'A provider error usually means no core Connector is configured for chat.',
				]
			);
		} else {
			$reply  = is_array( $reply ) ? $reply : [];
			$run_id = (string) ( $reply['run_id'] ?? '' );

			observe(
				$results,
				$quiet,
				'B1',
				'A real agents/chat turn completed',
				'' !== $run_id ? PASS : INCONCLUSIVE,
				[
					'runId'      => $run_id,
					'sessionId'  => $reply['session_id'] ?? '',
					'completed'  => $reply['completed'] ?? null,
					'elapsedSec' => $elapsed,
					'reply'      => substr( (string) ( $reply['reply'] ?? '' ), 0, 300 ),
				]
			);

			// Upstream's own content-redacted tool lifecycle record. This is the
			// evidence that the runtime dispatched Flavor Agent abilities as
			// tools, rather than the model describing them from its prompt.
			$calls = $reply['metadata']['agents_api']['tool_observability']['calls'] ?? [];
			$calls = is_array( $calls ) ? $calls : [];

			$ours = array_values(
				array_filter(
					$calls,
					static function ( $call ): bool {
						return is_array( $call )
							&& is_string( $call['tool_name'] ?? null )
							&& str_starts_with( $call['tool_name'], 'flavor-agent/' );
					}
				)
			);

			observe(
				$results,
				$quiet,
				'B2',
				'The runtime dispatched at least one Flavor Agent ability as a tool',
				[] !== $ours ? PASS : INCONCLUSIVE,
				[
					'flavorAgentCalls' => array_map(
						static fn( array $call ): array => [
							'tool'   => $call['tool_name'] ?? '',
							'status' => $call['status'] ?? '',
						],
						$ours
					),
					'allToolCalls'     => count( $calls ),
					'note'             => [] === $ours
						? 'The model answered without calling a tool. That is a model choice, not a wiring failure - re-run with a prompt that needs live site data.'
						: '',
				]
			);

			// B3: the attribution bridge. A recommendation made inside the run
			// should have written an activity row carrying this run id.
			//
			// One precondition decides whether this can possibly pass: when
			// core AI Request Logging is on and Flavor Agent's dual-logging
			// setting is off, no diagnostic row is written at all. Read that
			// first, so a legitimate "nothing to find" does not read as a
			// broken attribution bridge.
			$persists = ! class_exists( RequestLoggingBridge::class )
				|| RequestLoggingBridge::should_persist_request_diagnostic();

			$matched = [];

			if ( $persists && '' !== $run_id && class_exists( Repository::class ) ) {
				$page    = Repository::query_admin( [ 'perPage' => 50 ] );
				$entries = is_array( $page['entries'] ?? null ) ? $page['entries'] : [];

				foreach ( $entries as $entry ) {
					// Scan the whole row rather than one field: agentRun rides
					// in requestMeta on the ability payload, and which column
					// that lands in differs by surface.
					$encoded = is_array( $entry ) ? wp_json_encode( $entry ) : null;

					if ( is_string( $encoded ) && str_contains( $encoded, $run_id ) ) {
						$matched[] = is_array( $entry ) ? ( $entry['id'] ?? '(unknown id)' ) : '(unknown id)';
					}
				}
			}

			if ( ! $persists ) {
				$b3_status = SKIP;
				$b3_note   = 'Core AI Request Logging is on and dual logging is off, so no diagnostic row is written. '
					. 'Enable "also log to Flavor Agent activity" in Settings, or filter '
					. 'flavor_agent_persist_request_diagnostic_with_core_logging, then re-run.';
			} elseif ( [] !== $matched ) {
				$b3_status = PASS;
				$b3_note   = '';
			} elseif ( [] === $ours ) {
				$b3_status = SKIP;
				$b3_note   = 'No Flavor Agent tool ran this turn, so there was nothing to attribute.';
			} else {
				$b3_status = INCONCLUSIVE;
				$b3_note   = 'Tools ran but no row carries the run id. Read-only tools such as list-templates do not '
					. 'persist a row; if B2 shows only reads, this is expected rather than a bridge failure.';
			}

			observe(
				$results,
				$quiet,
				'B3',
				'An activity row carries the run id from this turn',
				$b3_status,
				[
					'runId'                => $run_id,
					'matchedRows'          => $matched,
					'diagnosticsPersisted' => $persists,
					'note'                 => $b3_note,
				]
			);
		}
	}
} elseif ( ! $quiet ) {
	echo "\nTier B - live run: not requested.\n";
	echo "  Re-run with FA_DEMO_TIERS=A,B,C to make a real provider call.\n";
}

// ---------------------------------------------------------------- Summary ---
$counts = array_count_values( array_column( $results, 'status' ) );

// `pass` has to mean "the evidence was gathered", not merely "nothing blew up".
// This harness exists to answer the Phase 1 live-run exit gate, and a tier B
// that was *asked for* but produced only skips and inconclusives gathered no
// live evidence at all — reporting that as `pass` would let a consumer reading
// DEMO_RESULT.status treat the gate as met on the strength of the wiring checks
// alone. Mirrors the pass/fail/incomplete vocabulary of scripts/verify.js.
// Every tier B check has to pass, not just the ones that happened to run. A
// `skip` counts against the gate exactly as an `inconclusive` does: B3 skips
// when core AI Request Logging is on and dual logging is off, which is a real
// site configuration in which B1 and B2 can both pass while no activity-row
// correlation was ever gathered. Calling that `pass` would let a consumer
// accept the exit gate without the attribution evidence that *is* the gate.
$requested_b   = in_array( 'B', $tiers, true );
$live_evidence = true;

foreach ( $results as $check ) {
	if ( str_starts_with( $check['id'], 'B' ) && PASS !== $check['status'] ) {
		$live_evidence = false;
	}
}

if ( ( $counts[ FAIL ] ?? 0 ) > 0 ) {
	$overall = FAIL;
} elseif ( ( $counts[ INCONCLUSIVE ] ?? 0 ) > 0 || ( $requested_b && ! $live_evidence ) ) {
	$overall = INCOMPLETE;
} else {
	$overall = PASS;
}

if ( ! $quiet ) {
	printf(
		"\n%d checks: %d pass, %d fail, %d inconclusive, %d skipped\noverall: %s\n",
		count( $results ),
		$counts[ PASS ] ?? 0,
		$counts[ FAIL ] ?? 0,
		$counts[ INCONCLUSIVE ] ?? 0,
		$counts[ SKIP ] ?? 0,
		strtoupper( $overall )
	);

	if ( INCOMPLETE === $overall ) {
		echo "  No live-run evidence was gathered. This is not a passing exit gate.\n";
	}

	echo "\n";
}

echo 'DEMO_RESULT=' . wp_json_encode(
	[
		'schemaVersion' => 1,
		'status'        => $overall,
		'tiers'         => $tiers,
		'counts'        => $counts,
		'checks'        => $results,
	]
) . "\n";
