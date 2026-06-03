<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BlockStructuralActionsFlagTest extends TestCase {

	public function test_enabled_by_default_without_option_or_filter(): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		$this->assertTrue( flavor_agent_block_structural_actions_enabled() );
	}

	/**
	 * The opt-out setting was removed when the feature graduated; a stale saved
	 * option value must no longer change the resolved state. Sites disable the
	 * feature through the flavor_agent_enable_block_structural_actions filter.
	 *
	 * @dataProvider saved_option_values
	 *
	 * @param mixed $value Stale saved option value.
	 */
	public function test_saved_option_value_is_ignored( mixed $value ): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		WordPressTestState::$options['flavor_agent_block_structural_actions_enabled'] = $value;

		$this->assertTrue( flavor_agent_block_structural_actions_enabled() );
	}

	public function test_plugin_does_not_define_force_enable_constant(): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		// The developer force-enable constant was retired alongside the option;
		// the filter is the only remaining control.
		$this->assertFalse( defined( 'FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS' ) );
	}

	/**
	 * @dataProvider false_like_values
	 *
	 * @param mixed $value False-like filtered value.
	 */
	public function test_filter_false_like_values_disable_block_structural_actions( mixed $value ): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		add_filter(
			'flavor_agent_enable_block_structural_actions',
			static fn () => $value
		);

		$this->assertFalse( flavor_agent_block_structural_actions_enabled() );
	}

	public function test_filter_true_like_values_keep_block_structural_actions_enabled(): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		add_filter(
			'flavor_agent_enable_block_structural_actions',
			static fn () => 'true'
		);

		$this->assertTrue( flavor_agent_block_structural_actions_enabled() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function saved_option_values(): array {
		return [
			'disabled bool'   => [ false ],
			'disabled string' => [ '0' ],
			'enabled bool'    => [ true ],
			'enabled string'  => [ '1' ],
		];
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function false_like_values(): array {
		return [
			'false string' => [ 'false' ],
			'no string'    => [ 'no' ],
			'off string'   => [ 'off' ],
			'zero string'  => [ '0' ],
			'zero integer' => [ 0 ],
			'false bool'   => [ false ],
			'null'         => [ null ],
		];
	}
}
