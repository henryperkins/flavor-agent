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
	 * @param array<string, mixed> $turn_context
	 * @return array<string, mixed>
	 */
	private function observe( array $turn_context ): array {
		$decision = RunContext::observe(
			[
				'action'   => 'proceed',
				'result'   => [],
				'complete' => false,
			],
			[ 'turn_context' => $turn_context ]
		);

		$this->assertIsArray( $decision );

		return $decision;
	}

	/**
	 * @param array<string, mixed> $request_meta
	 * @return array<string, mixed>
	 */
	private function append_agent_run_meta( array $request_meta ): array {
		$method = new ReflectionMethod( RecommendationAbilityExecution::class, 'append_agent_run_meta' );
		$method->setAccessible( true );

		/** @var array<string, mixed> $result */
		$result = $method->invoke( null, $request_meta );

		return $result;
	}
}
