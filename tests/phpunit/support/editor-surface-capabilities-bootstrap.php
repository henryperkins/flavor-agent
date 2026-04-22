<?php
/**
 * Test bootstrap for editor surface capability assertions.
 *
 * @package FlavorAgent\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FLAVOR_AGENT_TESTS_RUNNING' ) ) {
		exit;
	}

	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.test/wp-content/plugins/flavor-agent/';
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, $callback ): void {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, $callback ): void {}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'register_block_pattern_category' ) ) {
	function register_block_pattern_category( string $name, array $properties ): bool {
		return true;
	}
}

require_once dirname( __DIR__, 3 ) . '/flavor-agent.php';
