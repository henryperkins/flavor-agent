<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AgentsAPI\AgentDefinition;
use FlavorAgent\AgentsAPI\Compatibility;
use FlavorAgent\AgentsAPI\Registration;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * Boot cases where the Agents API runtime is absent or only partially loaded.
 *
 * Every test here runs in its own process: the adapter's activation boundary is
 * `defined()`/`class_exists()`/`function_exists()`, which cannot be un-done once
 * another test has stubbed the runtime into the current process. Process
 * isolation is what makes "Agents API absent" a real assertion rather than a
 * statement about test ordering.
 *
 * Exit gate for Phase 0: Flavor Agent behaves identically when Agents API is
 * inactive, and a compatibility failure disables only the adapter.
 *
 * @see docs/reference/agents-api-integration.md
 */
final class AgentsApiAdapterAbsentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		WordPressTestState::$options = [
			'wpai_features_enabled'             => true,
			'wpai_feature_flavor-agent_enabled' => true,
		];
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_status_reports_runtime_absent_when_agents_api_is_not_installed(): void {
		$this->assertFalse( \class_exists( 'WP_Agent' ), 'Guard: this process must not have the Agents API stubs loaded.' );

		$status = Compatibility::status();

		$this->assertFalse( $status['present'] );
		$this->assertFalse( $status['supported'] );
		$this->assertSame( 'runtime_absent', $status['reason'] );
		$this->assertSame( '', $status['version'] );
		$this->assertSame( Compatibility::MINIMUM_VERSION, $status['minimumVersion'] );
		$this->assertFalse( Compatibility::runtime_loaded() );
		$this->assertFalse( Compatibility::supported() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_registration_is_skipped_when_agents_api_is_not_installed(): void {
		$this->assertFalse( \function_exists( 'wp_register_agent' ), 'Guard: this process must not have the Agents API stubs loaded.' );

		\add_action( 'wp_agents_api_init', [ Registration::class, 'register' ] );
		\do_action( 'wp_agents_api_init' );

		$this->assertFalse( Registration::should_register() );
		$this->assertSame(
			0,
			WordPressTestState::$do_action_counts[ Registration::REGISTERED_ACTION ] ?? 0,
			'An absent runtime must not report a registered agent.'
		);
		$this->assertSame(
			0,
			WordPressTestState::$do_action_counts[ Registration::FAILED_ACTION ] ?? 0,
			'An absent runtime is not a failure — it is the documented no-op.'
		);
	}

	/**
	 * A loaded runtime whose classes are missing must fail closed rather than
	 * register against a contract it cannot verify.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_partially_loaded_runtime_fails_closed(): void {
		require_once __DIR__ . '/support/agents-api-partial-stubs.php';

		$status = Compatibility::status();

		$this->assertTrue( $status['present'], 'The bootstrap constant is defined, so the runtime is present.' );
		$this->assertFalse( $status['supported'] );
		$this->assertSame( 'contract_missing', $status['reason'] );
		$this->assertSame( 'class:WP_Agent', $status['missingContract'] );
		$this->assertFalse( Compatibility::contracts_available() );

		\add_action( 'wp_agents_api_init', [ Registration::class, 'register' ] );
		\do_action( 'wp_agents_api_init' );

		$this->assertSame(
			0,
			WordPressTestState::$do_action_counts[ Registration::REGISTERED_ACTION ] ?? 0
		);
	}

	/**
	 * The definition itself is inert without a runtime: building it must not
	 * touch Agents API symbols, so a site can inspect the intended tool profile
	 * whether or not the substrate is installed.
	 */
	public function test_tool_allowlist_never_contains_mutation_abilities(): void {
		$allowlist = AgentDefinition::tool_allowlist();

		$this->assertNotEmpty( $allowlist );

		foreach ( AgentDefinition::forbidden_abilities() as $forbidden ) {
			$this->assertNotContains(
				$forbidden,
				$allowlist,
				\sprintf( 'Phase 1 must not expose %s as a tool.', $forbidden )
			);
		}
	}
}
