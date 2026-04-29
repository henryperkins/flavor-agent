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
			'Block recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['block']['message']
		);
		$this->assertSame( '', $capabilities['pattern']['configurationLabel'] );
		$this->assertSame( '', $capabilities['pattern']['configurationUrl'] );
		$this->assertSame(
			'Pattern recommendations are not configured yet. Ask an administrator to configure Flavor Agent pattern backends and a text-generation provider in Settings > Connectors.',
			$capabilities['pattern']['message']
		);
		$this->assertSame( [], $capabilities['content']['actions'] );
		$this->assertSame( '', $capabilities['content']['configurationLabel'] );
		$this->assertSame(
			'Content recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['content']['message']
		);
		$this->assertSame( '', $capabilities['template']['configurationLabel'] );
		$this->assertSame( '', $capabilities['templatePart']['configurationLabel'] );
		$this->assertSame( [], $capabilities['navigation']['actions'] );
		$this->assertSame( '', $capabilities['navigation']['configurationLabel'] );
		$this->assertSame( [], $capabilities['globalStyles']['actions'] );
		$this->assertSame( '', $capabilities['globalStyles']['configurationLabel'] );
		$this->assertFalse( $capabilities['styleBook']['available'] );
		$this->assertSame( [], $capabilities['styleBook']['actions'] );
		$this->assertSame( '', $capabilities['styleBook']['configurationLabel'] );
		$this->assertSame(
			'Navigation recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['navigation']['message']
		);
		$this->assertSame(
			'Global Styles recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['globalStyles']['message']
		);
		$this->assertSame(
			'Style Book recommendations are not configured yet. Ask an administrator to configure a text-generation provider in Settings > Connectors.',
			$capabilities['styleBook']['message']
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
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['block']['actions']
		);
		$this->assertSame(
			'Settings > Connectors',
			$capabilities['template']['configurationLabel']
		);
		$this->assertSame(
			'https://example.test/wp-admin/options-connectors.php',
			$capabilities['template']['configurationUrl']
		);
		$this->assertSame(
			'Settings > Flavor Agent',
			$capabilities['pattern']['configurationLabel']
		);
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['content']['actions']
		);
		$this->assertSame( 'Settings > Connectors', $capabilities['content']['configurationLabel'] );
		$this->assertSame(
			'Configure a text-generation provider in Settings > Connectors to enable content recommendations.',
			$capabilities['content']['message']
		);
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['navigation']['actions']
		);
		$this->assertSame(
			'Settings > Connectors',
			$capabilities['globalStyles']['configurationLabel']
		);
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['globalStyles']['actions']
		);
		$this->assertFalse( $capabilities['styleBook']['available'] );
		$this->assertSame(
			[
				[
					'label' => 'Settings > Connectors',
					'href'  => 'https://example.test/wp-admin/options-connectors.php',
				],
			],
			$capabilities['styleBook']['actions']
		);
		$this->assertSame( 'Settings > Connectors', $capabilities['styleBook']['configurationLabel'] );
		$this->assertSame(
			'Configure a text-generation provider in Settings > Connectors to enable Style Book recommendations.',
			$capabilities['styleBook']['message']
		);
	}
}
