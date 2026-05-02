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

	/**
	 * @dataProvider false_like_values
	 *
	 * @param mixed $value False-like flag value.
	 */
	public function test_constant_false_like_values_do_not_enable_block_structural_actions( mixed $value ): void {
		WordPressTestState::reset();
		define( 'FLAVOR_AGENT_ENABLE_BLOCK_STRUCTURAL_ACTIONS', $value );
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		$this->assertFalse( flavor_agent_block_structural_actions_enabled() );
	}

	/**
	 * @dataProvider false_like_values
	 *
	 * @param mixed $value False-like filtered value.
	 */
	public function test_filter_false_like_values_do_not_enable_block_structural_actions( mixed $value ): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		add_filter(
			'flavor_agent_enable_block_structural_actions',
			static fn () => $value
		);

		$this->assertFalse( flavor_agent_block_structural_actions_enabled() );
	}

	public function test_filter_true_like_values_enable_block_structural_actions(): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		add_filter(
			'flavor_agent_enable_block_structural_actions',
			static fn () => 'true'
		);

		$this->assertTrue( flavor_agent_block_structural_actions_enabled() );
	}

	public function test_saved_admin_setting_enables_block_structural_actions(): void {
		WordPressTestState::reset();
		require dirname( __DIR__, 2 ) . '/flavor-agent.php';

		WordPressTestState::$options['flavor_agent_block_structural_actions_enabled'] = true;

		$this->assertTrue( flavor_agent_block_structural_actions_enabled() );
		$this->assertTrue(
			flavor_agent_get_editor_bootstrap_data(
				'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				'https://example.test/wp-admin/options-connectors.php'
			)['enableBlockStructuralActions']
		);
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
