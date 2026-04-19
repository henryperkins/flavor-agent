<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	private static bool $admin_assets_enqueued = false;

	public static function maybe_enqueue_assets( string $page_hook ): void {
		$registered_hook = function_exists( 'get_plugin_page_hookname' )
			? get_plugin_page_hookname( Config::PAGE_SLUG, 'options-general.php' )
			: '';

		if ( ! is_string( $registered_hook ) ) {
			$registered_hook = '';
		}

		if ( ! self::should_enqueue_assets( $page_hook, $registered_hook ) ) {
			return;
		}

		self::enqueue_assets();
	}

	public static function should_enqueue_assets( string $page_hook, string $registered_hook ): bool {
		if ( self::matches_known_page_hook( $page_hook, $registered_hook ) ) {
			return true;
		}

		if ( Config::PAGE_SLUG === self::get_requested_page_slug() ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! is_object( $screen ) ) {
			return false;
		}

		$screen_id   = is_string( $screen->id ?? null ) ? $screen->id : '';
		$screen_base = is_string( $screen->base ?? null ) ? $screen->base : '';

		return self::matches_known_page_hook( $screen_id, $registered_hook )
			|| self::matches_known_page_hook( $screen_base, $registered_hook );
	}

	/**
	 * @return array<int, string>
	 */
	public static function get_known_page_hooks( string $registered_hook = '' ): array {
		return array_values(
			array_unique(
				array_filter(
					[
						$registered_hook,
						'settings_page_' . Config::PAGE_SLUG,
						'admin_page_' . Config::PAGE_SLUG,
					]
				)
			)
		);
	}

	public static function enqueue_assets(): void {
		if ( self::$admin_assets_enqueued ) {
			return;
		}

		$asset_path = FLAVOR_AGENT_DIR . 'build/admin.asset.php';
		$asset      = self::read_asset_metadata( $asset_path );

		if ( null === $asset ) {
			return;
		}

		$css_path = FLAVOR_AGENT_DIR . 'build/admin.css';

		self::$admin_assets_enqueued = true;

		wp_enqueue_script(
			'flavor-agent-admin',
			FLAVOR_AGENT_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'flavor-agent-admin',
				FLAVOR_AGENT_URL . 'build/admin.css',
				[],
				$asset['version']
			);
		}

		wp_localize_script(
			'flavor-agent-admin',
			'flavorAgentAdmin',
			[
				'restUrl' => rest_url(),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * @return array{dependencies: array<int, string>, version: string|bool|null}|null
	 */
	public static function read_asset_metadata( string $asset_path ): ?array {
		if ( ! file_exists( $asset_path ) ) {
			return null;
		}

		$asset = include $asset_path;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'] ) || ! is_array( $asset['dependencies'] ) ) {
			return null;
		}

		$dependencies = array_values(
			array_filter(
				$asset['dependencies'],
				static fn ( mixed $dependency ): bool => is_string( $dependency ) && '' !== $dependency
			)
		);
		$version      = $asset['version'] ?? null;

		if ( null !== $version && ! is_string( $version ) && ! is_bool( $version ) ) {
			return null;
		}

		return [
			'dependencies' => $dependencies,
			'version'      => $version,
		];
	}

	public static function reset(): void {
		self::$admin_assets_enqueued = false;
	}

	private static function matches_known_page_hook( string $page_hook, string $registered_hook ): bool {
		if ( '' === $page_hook ) {
			return false;
		}

		return in_array( $page_hook, self::get_known_page_hooks( $registered_hook ), true );
	}

	private static function get_requested_page_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only admin screen detection.
		$page = wp_unslash( $_GET['page'] ?? '' );

		return is_string( $page ) ? sanitize_key( $page ) : '';
	}

	private function __construct() {
	}
}
