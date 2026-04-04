<?php

declare(strict_types=1);

namespace FlavorAgent\Admin;

use FlavorAgent\Activity\Repository as ActivityRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActivityPage {

	private const PAGE_SLUG = 'flavor-agent-activity';

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

		add_action(
			'admin_enqueue_scripts',
			static function ( string $page_hook ) use ( $hook ) {
				if ( $page_hook !== $hook ) {
					return;
				}

				self::enqueue_assets();
			}
		);
	}

	public static function render_page(): void {
		?>
		<div class="wrap">
			<div id="flavor-agent-activity-log-root"></div>
		</div>
		<?php
	}

	private static function enqueue_assets(): void {
		$asset_path = FLAVOR_AGENT_DIR . 'build/activity-log.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

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
