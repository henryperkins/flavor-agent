<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

require_once __DIR__ . '/support/editor-surface-capabilities-bootstrap.php';

use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class EditorSurfaceCapabilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_non_admin_site_editor_does_not_receive_configuration_links(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => false,
		];

		$capabilities = \flavor_agent_get_editor_surface_capabilities(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertSame( [], $capabilities['block']['actions'] );
		$this->assertSame(
			'Block recommendations are not configured yet. Ask an administrator to configure Flavor Agent or Connectors for this site.',
			$capabilities['block']['message']
		);
		$this->assertSame( '', $capabilities['pattern']['configurationLabel'] );
		$this->assertSame( '', $capabilities['pattern']['configurationUrl'] );
		$this->assertSame(
			'Pattern recommendations are not configured yet. Ask an administrator to configure Flavor Agent for this site.',
			$capabilities['pattern']['message']
		);
		$this->assertSame( '', $capabilities['template']['configurationLabel'] );
		$this->assertSame( '', $capabilities['templatePart']['configurationLabel'] );
		$this->assertSame( [], $capabilities['navigation']['actions'] );
		$this->assertSame( '', $capabilities['navigation']['configurationLabel'] );
		$this->assertSame(
			'Navigation recommendations are not configured yet. Ask an administrator to configure Flavor Agent for this site.',
			$capabilities['navigation']['message']
		);
	}

	public function test_admin_receives_configuration_links_for_unavailable_surfaces(): void {
		WordPressTestState::$capabilities = [
			'edit_theme_options' => true,
			'manage_options'     => true,
		];

		$capabilities = \flavor_agent_get_editor_surface_capabilities(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			'https://example.test/wp-admin/options-connectors.php'
		);

		$this->assertSame(
			[
				[
					'label' => 'Settings > Flavor Agent',
					'href'  => 'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				],
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['block']['actions']
		);
		$this->assertSame(
			'Settings > Flavor Agent',
			$capabilities['template']['configurationLabel']
		);
		$this->assertSame(
			'https://example.test/wp-admin/options-general.php?page=flavor-agent',
			$capabilities['template']['configurationUrl']
		);
		$this->assertSame(
			'Settings > Flavor Agent',
			$capabilities['pattern']['configurationLabel']
		);
		$this->assertSame(
			[
				[
					'label' => 'Settings > Flavor Agent',
					'href'  => 'https://example.test/wp-admin/options-general.php?page=flavor-agent',
				],
			],
			$capabilities['navigation']['actions']
		);
	}
}
