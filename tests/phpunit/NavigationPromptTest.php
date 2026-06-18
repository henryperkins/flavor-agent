<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\NavigationPrompt;
use PHPUnit\Framework\TestCase;

final class NavigationPromptTest extends TestCase {

	public function test_build_user_uses_navigation_scoped_budget_filter(): void {
		$captured = [];
		$filter   = static function ( int $value, string $surface ) use ( &$captured ): int {
			$captured[] = $surface;

			return $value;
		};

		add_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10, 2 );

		try {
			NavigationPrompt::build_user(
				[ 'location' => 'primary' ],
				'Reorganize the menu.'
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );
		}

		$this->assertContains( 'navigation', $captured );
	}

	public function test_build_user_keeps_user_instruction_under_extreme_budget_pressure(): void {
		$filter = static fn (): int => 2000;
		add_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );

		try {
			$prompt = NavigationPrompt::build_user(
				[ 'location' => str_repeat( 'L', 12000 ) ],
				'FLAVOR_AGENT_NAV_INSTRUCTION_MARKER'
			);
		} finally {
			remove_filter( 'flavor_agent_prompt_budget_max_tokens', $filter, 10 );
		}

		$this->assertStringContainsString( 'FLAVOR_AGENT_NAV_INSTRUCTION_MARKER', $prompt );
	}
}
