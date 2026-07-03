<?php

declare(strict_types=1);

namespace FlavorAgent\Admin;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Context\ServerCollector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActivityPage {

	private const PAGE_SLUG = 'flavor-agent-activity';

	private static bool $assets_enqueued = false;

	public static function add_menu(): void {
		if ( false === has_action( 'admin_notices', [ __CLASS__, 'render_pending_external_apply_notice' ] ) ) {
			add_action( 'admin_notices', [ __CLASS__, 'render_pending_external_apply_notice' ] );
		}

		$hook = add_submenu_page(
			'options-general.php',
			__( 'Flavor Agent Activity', 'flavor-agent' ),
			__( 'AI Activity', 'flavor-agent' ),
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
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-general.php?page=flavor-agent' ) ); ?>">
								<?php echo esc_html__( 'Flavor Agent settings', 'flavor-agent' ); ?>
							</a>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>">
								<?php echo esc_html__( 'Connectors', 'flavor-agent' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_pending_external_apply_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if ( self::is_activity_page_request() ) {
			return;
		}

		$snapshot = ActivityRepository::get_pending_external_apply_notification_snapshot();
		$count    = max( 0, (int) ( $snapshot['count'] ?? 0 ) );
		$entry    = is_array( $snapshot['latest'] ?? null ) ? $snapshot['latest'] : [];

		if ( $count <= 0 || [] === $entry ) {
			return;
		}

		$target_label    = self::format_target_context( $entry );
		$requester_label = self::format_requester_context( $entry );
		$expires_label   = self::format_expiry_context( $entry );
		$activity_url    = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$remaining_label = '';

		if ( $count > 1 ) {
			$remaining_label = sprintf(
				/* translators: %d: number of additional pending requests */
				__( ' and %d more pending request(s)', 'flavor-agent' ),
				$count - 1
			);
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html__( 'Pending external apply awaiting approval', 'flavor-agent' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: optional "and N more" suffix */
						__( ': review in AI Activity%s.', 'flavor-agent' ),
						$remaining_label
					)
				);
				?>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: requested target, 2: requester label, 3: expiry value */
						__( 'Target: %1$s. Requested by: %2$s. Expires: %3$s.', 'flavor-agent' ),
						$target_label,
						$requester_label,
						$expires_label
					)
				);
				?>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $activity_url ); ?>">
					<?php echo esc_html__( 'Open AI Activity', 'flavor-agent' ); ?>
				</a>
			</p>
		</div>
		<?php
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
			self::build_activity_log_boot_data()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function build_activity_log_boot_data(): array {
		return [
			'adminUrl'               => admin_url(),
			'canApproveStyleApplies' => current_user_can( 'edit_theme_options' ),
			'connectorsUrl'          => admin_url( 'options-connectors.php' ),
			'currentUserId'          => get_current_user_id(),
			'defaultPerPage'         => ActivityRepository::DEFAULT_PER_PAGE,
			'locale'                 => self::resolve_locale(),
			'maxPerPage'             => ActivityRepository::MAX_PER_PAGE,
			'nonce'                  => wp_create_nonce( 'wp_rest' ),
			'restUrl'                => rest_url(),
			'settingsUrl'            => admin_url( 'options-general.php?page=flavor-agent' ),
			'themeColorPresets'      => self::get_theme_color_presets(),
			'timeZone'               => self::resolve_timezone(),
		];
	}

	/**
	 * @return array<int, array{name: string, slug: string, color: string}>
	 */
	private static function get_theme_color_presets(): array {
		$presets       = ServerCollector::for_theme_presets();
		$color_presets = is_array( $presets['colorPresets'] ?? null ) ? $presets['colorPresets'] : [];
		$normalized    = [];

		foreach ( $color_presets as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}

			$slug  = sanitize_key( (string) ( $preset['slug'] ?? '' ) );
			$color = trim( (string) ( $preset['color'] ?? '' ) );
			$name  = sanitize_text_field( (string) ( $preset['name'] ?? '' ) );

			if ( '' === $slug || '' === $color ) {
				continue;
			}

			$normalized[] = [
				'name'  => $name,
				'slug'  => $slug,
				'color' => $color,
			];
		}

		return $normalized;
	}

	private static function is_activity_page_request(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( is_object( $screen ) ) {
			$screen_id = isset( $screen->id ) ? trim( (string) $screen->id ) : '';

			if ( in_array( $screen_id, [ 'settings_page_' . self::PAGE_SLUG, 'admin_page_' . self::PAGE_SLUG ], true ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug check for admin notice scoping.
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';

		return self::PAGE_SLUG === $page;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function format_target_context( array $entry ): string {
		$surface    = trim( (string) ( $entry['surface'] ?? '' ) );
		$target     = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$document   = is_array( $entry['document'] ?? null ) ? $entry['document'] : [];
		$styles_id  = trim( (string) ( $target['globalStylesId'] ?? $document['entityId'] ?? '' ) );
		$block_name = trim( (string) ( $target['blockName'] ?? '' ) );

		if ( 'post-blocks' === $surface ) {
			$title   = trim( (string) ( $target['title'] ?? '' ) );
			$post_id = trim( (string) ( $target['postId'] ?? $document['entityId'] ?? '' ) );

			if ( '' !== $title && '' !== $post_id ) {
				return sprintf(
					/* translators: 1: post title, 2: post ID. */
					__( 'Post: %1$s (#%2$s)', 'flavor-agent' ),
					$title,
					$post_id
				);
			}

			return '' !== $title
				/* translators: %s: post title. */
				? sprintf( __( 'Post: %s', 'flavor-agent' ), $title )
				: __( 'Post', 'flavor-agent' );
		}

		if ( 'style-book' === $surface && '' !== $block_name ) {
			$context = '' !== $styles_id
				/* translators: %s: global styles entity id. */
				? sprintf( __( 'Global Styles %s', 'flavor-agent' ), $styles_id )
				: __( 'Global Styles', 'flavor-agent' );

			return sprintf(
				/* translators: 1: block name, 2: global styles context */
				__( 'Style Book (%1$s, %2$s)', 'flavor-agent' ),
				$block_name,
				$context
			);
		}

		if ( '' !== $styles_id ) {
			return sprintf(
				/* translators: %s: global styles entity id */
				__( 'Global Styles %s', 'flavor-agent' ),
				$styles_id
			);
		}

		if ( 'style-book' === $surface ) {
			return __( 'Style Book', 'flavor-agent' );
		}

		return __( 'Global Styles', 'flavor-agent' );
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function format_requester_context( array $entry ): string {
		$apply             = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$requested_by      = (int) ( $apply['requestedBy'] ?? 0 );
		$user_label        = trim( (string) ( $entry['userLabel'] ?? '' ) );
		$request_reference = trim( (string) ( $apply['requestReference'] ?? '' ) );
		$label             = '' !== $user_label
			? $user_label
			/* translators: %d: requester user ID. */
			: ( $requested_by > 0 ? sprintf( __( 'User #%d', 'flavor-agent' ), $requested_by ) : __( 'Unknown requester', 'flavor-agent' ) );

		if ( '' !== $request_reference ) {
			return sprintf(
				/* translators: 1: requester label, 2: requester reference */
				__( '%1$s (reference: %2$s)', 'flavor-agent' ),
				$label,
				$request_reference
			);
		}

		return $label;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function format_expiry_context( array $entry ): string {
		$apply      = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$expires_at = trim( (string) ( $apply['expiresAt'] ?? '' ) );

		if ( '' === $expires_at ) {
			return __( 'Unknown', 'flavor-agent' );
		}

		$timestamp = strtotime( $expires_at );

		if ( false === $timestamp ) {
			return $expires_at;
		}

		return gmdate( 'Y-m-d H:i:s \U\T\C', $timestamp );
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
