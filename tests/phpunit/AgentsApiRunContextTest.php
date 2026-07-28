<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\RecommendationAbilityExecution;
use FlavorAgent\AgentsAPI\RunContext;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Run/session correlation between an Agents API run and Flavor Agent attribution.
 *
 * The contract is narrow on purpose: opaque identifiers only, size-bounded, and
 * never a substitute for the activity record itself. An Agents API transcript
 * explains the conversation; it does not prove what changed on the site.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentsApiRunContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		RunContext::reset();
	}

	protected function tearDown(): void {
		RunContext::reset();

		parent::tearDown();
	}

	public function test_no_run_context_outside_an_agent_run(): void {
		$this->assertFalse( RunContext::is_active() );
		$this->assertSame( [], RunContext::current() );
	}

	public function test_captures_the_run_identifiers_from_the_tool_call_seam(): void {
		$decision = $this->observe(
			[
				'agent_slug' => 'flavor-agent',
				'run_id'     => 'run_01HTESTRUN',
				'session_id' => 'sess_01HTESTSESSION',
			]
		);

		$this->assertSame( 'proceed', $decision['action'], 'The observer must return the decision unchanged.' );
		$this->assertTrue( RunContext::is_active() );
		$this->assertSame(
			[
				'agentSlug' => 'flavor-agent',
				'runId'     => 'run_01HTESTRUN',
				'sessionId' => 'sess_01HTESTSESSION',
			],
			RunContext::current()
		);
	}

	public function test_never_captures_principal_parameters_or_transcript_content(): void {
		RunContext::observe(
			[ 'action' => 'proceed' ],
			[
				'turn_context'  => [
					'agent_slug' => 'flavor-agent',
					'run_id'     => 'run_1',
					'session_id' => 'sess_1',
					'principal'  => [
						'token_id' => 'tok_secret',
						'user_id'  => 7,
					],
				],
				'parameters'    => [
					'prompt' => 'a private instruction',
					'apiKey' => 'sk-secret',
				],
				'messages'      => [
					[
						'role'    => 'user',
						'content' => 'transcript content',
					],
				],
				'raw_tool_call' => [ 'arguments' => '{"apiKey":"sk-secret"}' ],
			]
		);

		$captured = RunContext::current();

		$this->assertSame( [ 'agentSlug', 'runId', 'sessionId' ], \array_keys( $captured ) );

		$encoded = \json_encode( $captured );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( 'sk-secret', $encoded );
		$this->assertStringNotContainsString( 'tok_secret', $encoded );
		$this->assertStringNotContainsString( 'transcript content', $encoded );
		$this->assertStringNotContainsString( 'private instruction', $encoded );
	}

	public function test_normalizes_and_bounds_untrusted_identifiers(): void {
		$this->observe(
			[
				'agent_slug' => "  flavor-agent\n<script>  ",
				'run_id'     => \str_repeat( 'r', 400 ),
				'session_id' => '   ',
			]
		);

		$captured = RunContext::current();

		$this->assertSame( 'flavor-agentscript', $captured['agentSlug'] );
		$this->assertSame( 128, \strlen( $captured['runId'] ) );
		$this->assertArrayNotHasKey( 'sessionId', $captured, 'An empty identifier is omitted rather than stored blank.' );
	}

	/**
	 * `reject`, `replace_result` and `pending` all short-circuit with a
	 * synthesized result — the ability never runs. Capturing anyway would leave
	 * a context bound to an ability that did not execute, which a later
	 * non-agent call of that same ability could then consume.
	 *
	 * @dataProvider non_dispatching_decisions
	 */
	public function test_does_not_capture_a_call_that_never_reaches_its_ability( string $action ): void {
		RunContext::observe(
			[
				'action'   => $action,
				'result'   => [],
				'complete' => false,
			],
			[
				'turn_context' => [
					'agent_slug' => 'flavor-agent',
					'run_id'     => 'run_01HTESTRUN',
				],
				'tool_name'    => 'flavor-agent/recommend-block',
			]
		);

		$this->assertFalse( RunContext::is_active(), \sprintf( 'A %s decision was captured.', $action ) );
		$this->assertSame( '', RunContext::current_ability() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function non_dispatching_decisions(): array {
		return [
			'reject'         => [ 'reject' ],
			'replace_result' => [ 'replace_result' ],
			'pending'        => [ 'pending' ],
		];
	}

	/**
	 * A rejection after an earlier capture must clear it, not leave the prior
	 * call's identifiers standing.
	 */
	public function test_a_rejection_clears_a_previously_captured_context(): void {
		$this->observe(
			[
				'agent_slug' => 'flavor-agent',
				'run_id'     => 'run_01HTESTRUN',
			]
		);

		$this->assertTrue( RunContext::is_active() );

		RunContext::observe(
			[ 'action' => 'reject' ],
			[
				'turn_context' => [ 'agent_slug' => 'flavor-agent' ],
				'tool_name'    => 'flavor-agent/recommend-block',
			]
		);

		$this->assertFalse( RunContext::is_active() );
	}

	public function test_ignores_a_malformed_mediation_context(): void {
		$this->assertSame( 'proceed', RunContext::observe( 'proceed', null ) );
		$this->assertSame( [], RunContext::current() );

		RunContext::observe( [ 'action' => 'proceed' ], [ 'turn_context' => 'not-an-array' ] );
		$this->assertSame( [], RunContext::current() );
	}

	public function test_request_meta_omits_agent_run_outside_an_agent_run(): void {
		$this->assertArrayNotHasKey( 'agentRun', $this->append_agent_run_meta( [ 'ability' => 'flavor-agent/recommend-block' ] ) );
	}

	public function test_request_meta_correlates_an_agent_originated_recommendation(): void {
		$this->observe(
			[
				'agent_slug' => 'flavor-agent',
				'run_id'     => 'run_01HTESTRUN',
				'session_id' => 'sess_01HTESTSESSION',
			]
		);

		$request_meta = $this->append_agent_run_meta(
			[
				'ability'            => 'flavor-agent/recommend-block',
				'executionTransport' => 'wp-abilities',
			]
		);

		$this->assertSame(
			[
				'agentSlug' => 'flavor-agent',
				'runId'     => 'run_01HTESTRUN',
				'sessionId' => 'sess_01HTESTSESSION',
			],
			$request_meta['agentRun']
		);
		$this->assertSame(
			'wp-abilities',
			$request_meta['executionTransport'],
			'The agent runtime mediates the tool call; it does not replace the ability dispatch path.'
		);
	}

	/**
	 * The seam is a *pre*-call hook and upstream has no post-call counterpart,
	 * so nothing signals that a tool call finished. Without a lifecycle bound,
	 * a finished run's identifiers would sit in a static for the rest of the
	 * process and be stamped onto whatever recommendation ran next — a
	 * long-lived WP-CLI process, or a workflow dispatching an ability step
	 * after a chat step. Attribution that is confidently wrong is worse than
	 * absent, so the context is consumed on read.
	 */
	public function test_a_finished_run_does_not_attribute_a_later_recommendation(): void {
		$this->observe(
			[
				'agent_slug' => 'flavor-agent',
				'run_id'     => 'run_01HTESTRUN',
			]
		);

		$first = $this->append_agent_run_meta( [ 'ability' => 'flavor-agent/recommend-block' ] );
		$this->assertArrayHasKey( 'agentRun', $first );

		$second = $this->append_agent_run_meta( [ 'ability' => 'flavor-agent/recommend-block' ] );
		$this->assertArrayNotHasKey(
			'agentRun',
			$second,
			'A second recommendation in the same process inherited the finished run.'
		);
	}

	/**
	 * A run does not license attribution of *any* ability — only the one the
	 * runtime said it was dispatching.
	 */
	public function test_does_not_attribute_an_ability_the_runtime_was_not_dispatching(): void {
		$this->observe(
			[
				'agent_slug' => 'flavor-agent',
				'run_id'     => 'run_01HTESTRUN',
			],
			'flavor-agent/recommend-block'
		);

		$this->assertArrayNotHasKey(
			'agentRun',
			$this->append_agent_run_meta( [], 'flavor-agent/recommend-style' )
		);

		// The bound ability is untouched by the mismatched read.
		$this->assertArrayHasKey(
			'agentRun',
			$this->append_agent_run_meta( [], 'flavor-agent/recommend-block' )
		);
	}

	/**
	 * `execute()` returns early for signature-only resolution, before either
	 * request-meta builder runs. Consuming inside those builders therefore left
	 * this path unconsumed, and the context survived to attribute a later call
	 * of the same ability. Consumption moved to ability entry; this pins it
	 * there.
	 */
	public function test_signature_only_resolution_still_consumes_the_run_context(): void {
		$this->observe(
			[
				'agent_slug' => 'flavor-agent',
				'run_id'     => 'run_01HTESTRUN',
			],
			'flavor-agent/recommend-block'
		);

		$this->assertTrue( RunContext::is_active() );

		RecommendationAbilityExecution::execute(
			'block',
			'flavor-agent/recommend-block',
			[ 'resolveSignatureOnly' => true ],
			static fn(): array => [ 'signatures' => [] ]
		);

		$this->assertSame(
			[],
			RunContext::current(),
			'A signature-only call left the run context set for the next execution of this ability.'
		);
	}

	/**
	 * A tool declaration may map a model-facing name onto a different registered
	 * ability. Upstream resolves `ability` / `ability_name` ahead of the tool
	 * name, and so must this, or attribution binds to a name that never
	 * executes and is silently dropped.
	 */
	public function test_resolves_the_ability_behind_a_renamed_tool(): void {
		$this->observe(
			[
				'agent_slug' => 'flavor-agent',
				'run_id'     => 'run_01HTESTRUN',
			],
			'flavor_agent_recommend_style',
			[ 'ability' => 'flavor-agent/recommend-style' ]
		);

		$this->assertSame( 'flavor-agent/recommend-style', RunContext::current_ability() );
		$this->assertArrayHasKey(
			'agentRun',
			$this->append_agent_run_meta( [], 'flavor-agent/recommend-style' )
		);
	}

	/**
	 * @param array<string, mixed> $turn_context
	 * @return array<string, mixed>
	 */
	private function observe(
		array $turn_context,
		string $tool_name = 'flavor-agent/recommend-block',
		array $tool_declaration = []
	): array {
		$decision = RunContext::observe(
			[
				'action'   => 'proceed',
				'result'   => [],
				'complete' => false,
			],
			[
				'turn_context'     => $turn_context,
				'tool_name'        => $tool_name,
				'tool_declaration' => $tool_declaration,
			]
		);

		$this->assertIsArray( $decision );

		return $decision;
	}

	/**
	 * @param array<string, mixed> $request_meta
	 * @return array<string, mixed>
	 */
	private function append_agent_run_meta(
		array $request_meta,
		string $ability_name = 'flavor-agent/recommend-block'
	): array {
		$method = new ReflectionMethod( RecommendationAbilityExecution::class, 'append_agent_run_meta' );
		$method->setAccessible( true );

		// Mirrors `execute()`: consume once, then hand the result to the
		// request-meta builder.
		/** @var array<string, mixed> $result */
		$result = $method->invoke( null, $request_meta, RunContext::consume( $ability_name ) );

		return $result;
	}
}
