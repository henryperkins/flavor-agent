<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Abilities\InfraAbilities;
use FlavorAgent\AgentsAPI\Compatibility;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Version gating for a supported Agents API runtime.
 *
 * The adapter is pinned to a released upstream tag because the project is
 * pre-1.0: the registration keys and tool-policy semantics it depends on are
 * versioned API, and an unknown build is treated as unsupported rather than
 * adapted to.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentsApiCompatibilityTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once __DIR__ . '/support/agents-api-stubs.php';
	}

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_runtime_is_detected_once_the_bootstrap_constant_is_defined(): void {
		$this->assertTrue( Compatibility::runtime_loaded() );
		$this->assertTrue( Compatibility::contracts_available() );
		$this->assertSame( '', Compatibility::missing_contract() );
	}

	/**
	 * @dataProvider version_provider
	 */
	public function test_version_gate( string $version, bool $expected_supported, string $expected_reason ): void {
		$this->pin_version( $version );

		$status = Compatibility::status();

		$this->assertSame( $expected_supported, Compatibility::version_supported() );
		$this->assertSame( $expected_supported, Compatibility::supported() );
		$this->assertSame( $expected_reason, $status['reason'] );
		$this->assertSame( $version, $status['version'] );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: string}>
	 */
	public static function version_provider(): array {
		return [
			'undetectable version fails closed' => [ '', false, 'version_undetectable' ],
			'older tag is unsupported'          => [ '0.6.1', false, 'version_unsupported' ],
			'pinned minimum is supported'       => [ Compatibility::MINIMUM_VERSION, true, 'ok' ],
			'newer patch is supported'          => [ '0.7.3', true, 'ok' ],
			'newer minor is supported'          => [ '0.8.0', true, 'ok' ],
			'post-1.0 release is supported'     => [ '1.0.0', true, 'ok' ],
		];
	}

	public function test_check_status_reports_agent_runtime_separately_from_backends(): void {
		$this->pin_version( Compatibility::MINIMUM_VERSION );

		$status = InfraAbilities::check_status( [] );

		$this->assertArrayHasKey( 'agentRuntime', $status );
		$this->assertArrayHasKey( 'backends', $status );
		$this->assertArrayNotHasKey(
			'agentRuntime',
			$status['backends'],
			'Agent-runtime readiness must not be reported as a recommendation backend.'
		);
		$this->assertTrue( $status['agentRuntime']['supported'] );
		$this->assertSame( Compatibility::MINIMUM_VERSION, $status['agentRuntime']['version'] );
	}

	public function test_check_status_agent_runtime_does_not_change_configured_readiness(): void {
		$this->pin_version( '0.6.1' );
		$unsupported = InfraAbilities::check_status( [] );

		WordPressTestState::reset();
		$this->pin_version( Compatibility::MINIMUM_VERSION );
		$supported = InfraAbilities::check_status( [] );

		$this->assertFalse( $unsupported['agentRuntime']['supported'] );
		$this->assertTrue( $supported['agentRuntime']['supported'] );
		$this->assertSame(
			$unsupported['configured'],
			$supported['configured'],
			'An unsupported agent runtime must not make recommendations look unconfigured.'
		);
		$this->assertSame( $unsupported['availableAbilities'], $supported['availableAbilities'] );
	}

	private function pin_version( string $version ): void {
		\add_filter(
			Compatibility::VERSION_FILTER,
			static fn(): string => $version
		);
	}
}
