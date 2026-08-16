<?php
/**
 * A deliberately incomplete Agents API runtime: the bootstrap constant and the
 * registration functions exist, but the classes the adapter binds to do not.
 *
 * Models the real risk this integration guards against — a pre-1.0 upstream
 * whose loaded surface is not the surface the adapter compiled against — so the
 * fail-closed path can be tested rather than assumed.
 *
 * Loaded only from a process-isolated test. Never require this alongside
 * `agents-api-stubs.php`.
 */

declare(strict_types=1);

namespace {

	if ( ! defined( 'AGENTS_API_LOADED' ) ) {
		define( 'AGENTS_API_LOADED', true );
	}

	if ( ! function_exists( 'wp_register_agent' ) ) {
		function wp_register_agent( $agent, array $args = [] ) {
			unset( $agent, $args );

			return null;
		}
	}

	if ( ! function_exists( 'wp_get_agent' ) ) {
		function wp_get_agent( string $slug ) {
			unset( $slug );

			return null;
		}
	}
}
