<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Context\BlockTypeIntrospector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Ensures the PHP SUPPORT_TO_PANEL map in BlockTypeIntrospector stays in
 * lockstep with the JS map in src/context/block-inspector.js. These two
 * lookups must agree because ServerCollector and the client-side collector
 * both use them to route block supports into Inspector panels.
 */
final class SupportToPanelSyncTest extends TestCase {

	public function test_php_and_js_support_to_panel_maps_match(): void {
		$php_map = $this->get_php_support_to_panel_map();
		$js_map  = $this->parse_js_support_to_panel_map();

		$this->assertNotEmpty( $php_map, 'PHP SUPPORT_TO_PANEL map should not be empty.' );
		$this->assertNotEmpty( $js_map, 'JS SUPPORT_TO_PANEL map should not be empty.' );

		ksort( $php_map );
		ksort( $js_map );

		$this->assertSame(
			$php_map,
			$js_map,
			'SUPPORT_TO_PANEL maps have drifted between BlockTypeIntrospector.php and src/context/block-inspector.js.'
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_php_support_to_panel_map(): array {
		$reflection = new ReflectionClass( BlockTypeIntrospector::class );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey(
			'SUPPORT_TO_PANEL',
			$constants,
			'BlockTypeIntrospector::SUPPORT_TO_PANEL must exist.'
		);

		/** @var array<string, string> $map */
		$map = $constants['SUPPORT_TO_PANEL'];

		return $map;
	}

	/**
	 * @return array<string, string>
	 */
	private function parse_js_support_to_panel_map(): array {
		$source_path = dirname( __DIR__, 2 ) . '/src/context/block-inspector.js';
		$this->assertFileExists( $source_path );

		$source = file_get_contents( $source_path );
		$this->assertIsString( $source );

		$anchor = 'const SUPPORT_TO_PANEL = {';
		$start  = strpos( $source, $anchor );
		$this->assertNotFalse( $start, 'Could not locate SUPPORT_TO_PANEL declaration.' );

		$body_start = $start + strlen( $anchor );
		$body_end   = strpos( $source, '};', $body_start );
		$this->assertNotFalse( $body_end, 'Could not locate end of SUPPORT_TO_PANEL declaration.' );

		$body = substr( $source, $body_start, $body_end - $body_start );

		$map = [];

		// Match quoted keys: 'foo.bar': 'panel',
		if ( preg_match_all( "/'([^'\\n]+)'\\s*:\\s*'([^'\\n]+)'/", $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$map[ $match[1] ] = $match[2];
			}
		}

		// Match bareword keys: shadow: 'shadow',
		if ( preg_match_all( "/(?:^|[\\s,{])([A-Za-z_][A-Za-z0-9_]*)\\s*:\\s*'([^'\\n]+)'/", $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$map[ $match[1] ] = $match[2];
			}
		}

		return $map;
	}
}
