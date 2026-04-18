<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Admin\ActivityPage;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ActivityPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];

		parent::tearDown();
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

	public function test_should_enqueue_assets_accepts_settings_and_admin_hook_variants(): void {
		$method = new ReflectionMethod( ActivityPage::class, 'should_enqueue_assets' );
		$method->setAccessible( true );

		$this->assertTrue(
			$method->invoke( null, 'settings_page_flavor-agent-activity', 'admin_page_flavor-agent-activity' )
		);
		$this->assertTrue(
			$method->invoke( null, 'admin_page_flavor-agent-activity', 'settings_page_flavor-agent-activity' )
		);
	}

	public function test_should_enqueue_assets_falls_back_to_request_slug_and_current_screen(): void {
		$method = new ReflectionMethod( ActivityPage::class, 'should_enqueue_assets' );
		$method->setAccessible( true );

		$_GET = [
			'page' => 'flavor-agent-activity',
		];

		$this->assertTrue(
			$method->invoke( null, 'dashboard_page_demo', 'settings_page_flavor-agent-activity' )
		);

		$_GET = [];
		WordPressTestState::$current_screen = (object) [
			'id'   => 'settings_page_flavor-agent-activity',
			'base' => 'settings_page_flavor-agent-activity',
		];

		$this->assertTrue(
			$method->invoke( null, 'dashboard_page_demo', 'admin_page_flavor-agent-activity' )
		);
	}

	public function test_should_enqueue_assets_rejects_other_admin_pages(): void {
		$method = new ReflectionMethod( ActivityPage::class, 'should_enqueue_assets' );
		$method->setAccessible( true );

		$_GET = [
			'page' => 'plugins',
		];
		WordPressTestState::$current_screen = (object) [
			'id'   => 'plugins',
			'base' => 'plugins',
		];

		$this->assertFalse(
			$method->invoke( null, 'plugins.php', 'settings_page_flavor-agent-activity' )
		);
	}
}
