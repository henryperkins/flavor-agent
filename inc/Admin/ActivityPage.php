<?php

declare(strict_types=1);

namespace FlavorAgent\Admin;

use FlavorAgent\Activity\Repository as ActivityRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActivityPage {

	private const PAGE_SLUG = 'flavor-agent-activity';

	private static bool $assets_enqueued = false;

	public static function add_menu(): void {
		$hook = add_submenu_page(
			'options-general.php',
			'Flavor Agent Activity',
			'AI Activity',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		if ( ! $hook ) {
			return;
		}

		foreach ( self::get_known_page_hooks( $hook ) as $known_hook ) {
			add_action( "load-$known_hook", [ __CLASS__, 'handle_page_load' ] );
		}
	}

	public static function handle_page_load(): void {
		self::enqueue_assets();
	}

	public static function maybe_enqueue_assets( string $page_hook ): void {
		$registered_hook = function_exists( 'get_plugin_page_hookname' )
			? get_plugin_page_hookname( self::PAGE_SLUG, 'options-general.php' )
			: '';

		if ( ! is_string( $registered_hook ) ) {
			$registered_hook = '';
		}

		if ( ! self::should_enqueue_assets( $page_hook, $registered_hook ) ) {
			return;
		}

		self::enqueue_assets();
	}

	public static function render_page(): void {
		?>
		<div class="wrap">
			<div id="flavor-agent-activity-log-root">
				<div class="flavor-agent-activity-log__fallback">
					<h1><?php echo esc_html__( 'AI Activity Log', 'flavor-agent' ); ?></h1>
					<div class="notice notice-warning inline">
						<p>
							<?php
							echo esc_html__(
								'Flavor Agent could not load the interactive activity log. Reload this page. If the problem persists, rebuild the plugin assets and try again.',
								'flavor-agent'
							);
							?>
						</p>
						<p>
							<a class="button button-secondary" href="<?php echo esc_attr( admin_url( 'options-general.php?page=flavor-agent' ) ); ?>">
								<?php echo esc_html__( 'Flavor Agent settings', 'flavor-agent' ); ?>
							</a>
							<a class="button button-secondary" href="<?php echo esc_attr( admin_url( 'options-connectors.php' ) ); ?>">
								<?php echo esc_html__( 'Connectors', 'flavor-agent' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function should_enqueue_assets( string $page_hook, string $registered_hook ): bool {
		if ( self::matches_known_page_hook( $page_hook, $registered_hook ) ) {
			return true;
		}

		if ( self::PAGE_SLUG === self::get_requested_page_slug() ) {
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

	private static function matches_known_page_hook( string $page_hook, string $registered_hook ): bool {
		if ( '' === $page_hook ) {
			return false;
		}

		return in_array( $page_hook, self::get_known_page_hooks( $registered_hook ), true );
	}

	/**
	 * @return array<int, string>
	 */
	private static function get_known_page_hooks( string $registered_hook = '' ): array {
		return array_values(
			array_unique(
				array_filter(
					[
						$registered_hook,
						'settings_page_' . self::PAGE_SLUG,
						'admin_page_' . self::PAGE_SLUG,
					]
				)
			)
		);
	}

	private static function get_requested_page_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only admin screen detection.
		$page = wp_unslash( $_GET['page'] ?? '' );

		return is_string( $page ) ? sanitize_key( $page ) : '';
	}

	private static function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}

		$asset_path = FLAVOR_AGENT_DIR . 'build/activity-log.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		self::$assets_enqueued = true;

		$asset               = include $asset_path;
		$css_path            = FLAVOR_AGENT_DIR . 'build/activity-log.css';
		$script_dependencies = array_values(
			array_filter(
				$asset['dependencies'],
				static fn ( string $dependency ): bool => ! str_ends_with( $dependency, '.css' )
			)
		);

		wp_enqueue_script(
			'flavor-agent-activity-log',
			FLAVOR_AGENT_URL . 'build/activity-log.js',
			$script_dependencies,
			$asset['version'],
			true
		);

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'flavor-agent-activity-log',
				FLAVOR_AGENT_URL . 'build/activity-log.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}

		wp_localize_script(
			'flavor-agent-activity-log',
			'flavorAgentActivityLog',
			[
				'adminUrl'       => admin_url(),
				'connectorsUrl'  => admin_url( 'options-connectors.php' ),
				'defaultPerPage' => ActivityRepository::DEFAULT_PER_PAGE,
				'locale'         => self::resolve_locale(),
				'maxPerPage'     => ActivityRepository::MAX_PER_PAGE,
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'restUrl'        => rest_url(),
				'settingsUrl'    => admin_url( 'options-general.php?page=flavor-agent' ),
				'timeZone'       => self::resolve_timezone(),
			]
		);
	}

	private static function resolve_locale(): string {
		if ( function_exists( 'determine_locale' ) ) {
			$locale = determine_locale();

			if ( is_string( $locale ) && '' !== $locale ) {
				return $locale;
			}
		}

		if ( function_exists( 'get_locale' ) ) {
			$locale = get_locale();

			if ( is_string( $locale ) && '' !== $locale ) {
				return $locale;
			}
		}

		return 'en-US';
	}

	private static function resolve_timezone(): string {
		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezone = wp_timezone_string();

			if ( is_string( $timezone ) && '' !== $timezone ) {
				return $timezone;
			}
		}

		$timezone = get_option( 'timezone_string', 'UTC' );

		return is_string( $timezone ) && '' !== $timezone ? $timezone : 'UTC';
	}
}
