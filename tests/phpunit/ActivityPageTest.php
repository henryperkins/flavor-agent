<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\ActivityPage;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ActivityPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_render_page_outputs_a_server_side_fallback_shell(): void {
		ob_start();
		ActivityPage::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString(
			'flavor-agent-activity-log-root',
			$output
		);
		$this->assertStringContainsString( 'AI Activity Log', $output );
		$this->assertStringContainsString(
			'Flavor Agent could not load the interactive activity log.',
			$output
		);
		$this->assertStringContainsString(
			'options-general.php?page=flavor-agent',
			$output
		);
		$this->assertStringContainsString(
			'options-connectors.php',
			$output
		);
	}
}
