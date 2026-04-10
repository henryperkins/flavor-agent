<?php

declare(strict_types=1);

use FlavorAgent\Context\BlockTypeIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * Validates that the shared/support-to-panel.json fixture stays in sync
 * with the PHP SUPPORT_TO_PANEL constant.
 */
final class SupportToPanelSyncTest extends TestCase {

	public function test_shared_json_matches_php_constant(): void {
		$json_path = dirname( __DIR__, 2 ) . '/shared/support-to-panel.json';
		$this->assertFileExists( $json_path, 'shared/support-to-panel.json must exist' );

		$raw     = file_get_contents( $json_path );
		$decoded = json_decode( (string) $raw, true );
		$this->assertIsArray( $decoded, 'shared/support-to-panel.json must decode to an array' );

		// The static accessor reads the same file but validates the wiring.
		$php_map = BlockTypeIntrospector::get_support_to_panel();

		$this->assertSame(
			$decoded,
			$php_map,
			'BlockTypeIntrospector::get_support_to_panel() must match shared/support-to-panel.json exactly'
		);
	}

	public function test_shared_json_has_expected_entry_count(): void {
		$json_path = dirname( __DIR__, 2 ) . '/shared/support-to-panel.json';
		$decoded   = json_decode( (string) file_get_contents( $json_path ), true );

		// 39 entries as of the initial extraction — this guards against
		// accidental truncation or duplication.
		$this->assertCount(
			39,
			$decoded,
			'shared/support-to-panel.json should have exactly 39 entries'
		);
	}

	public function test_all_panel_values_are_known(): void {
		$known_panels = [
			'color',
			'typography',
			'dimensions',
			'border',
			'shadow',
			'filter',
			'background',
			'position',
			'layout',
			'advanced',
			'list',
		];

		$json_path = dirname( __DIR__, 2 ) . '/shared/support-to-panel.json';
		$decoded   = json_decode( (string) file_get_contents( $json_path ), true );

		foreach ( $decoded as $key => $panel ) {
			$this->assertContains(
				$panel,
				$known_panels,
				"Unexpected panel value '$panel' for key '$key'"
			);
		}
	}
}
