<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use PHPUnit\Framework\TestCase;

final class PluginAutoloadTest extends TestCase {

	/** @dataProvider composer_states */
	public function test_bootstrap_keeps_persistence_hooks_available_when_composer_misses_classes( string $mode, bool $composer_miss ): void {
		$script  = __DIR__ . '/fixtures/plugin-autoload.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $mode ) . ' 2>&1';
		$output  = [];
		$status  = 1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Autoload behavior must run before PHPUnit or another test loads plugin classes.
		exec( $command, $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		$this->assertSame( $composer_miss, $result['composerMiss'] ?? null );
		$this->assertTrue( $result['saveHookRegistered'] ?? false );
		$this->assertTrue( $result['workerHookCallable'] ?? false );
		$this->assertTrue( $result['lateClassLoadable'] ?? false );
	}

	public static function composer_states(): array {
		return [
			'normal PSR-4 loader'          => [ 'normal', false ],
			'stale authoritative classmap' => [ 'authoritative', true ],
			'cached class lookup miss'     => [ 'cached-miss', true ],
		];
	}
}
