<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

/**
 * Runtime probes for theme.json style-path support.
 *
 * Style properties reach sites at different times and by different routes. The
 * Gutenberg plugin ships `WP_Theme_JSON_Gutenberg`, which shadows core's class,
 * so a WP 7.0 site running Gutenberg 23.5 honors paths that `$wp_version` alone
 * would deny — and an older Gutenberg shadows a newer core in the other
 * direction. Only the class actually in play can answer, so this probes
 * behavior instead of comparing version numbers.
 *
 * Two distinct questions are answered separately, because they have different
 * scopes and different answers:
 *
 * - `supports_style_path()` — does the path survive sanitization, i.e. will a
 *   value written there render as CSS? Site-wide, driven by the class's
 *   `VALID_STYLES` schema.
 * - `current_user_can_persist_style_path()` — will the value survive being
 *   saved by *this* user? Global styles are run through
 *   `remove_insecure_properties()` on save, but only for users without
 *   `unfiltered_html` (see `kses_init()`). That path filters each computed
 *   declaration through `safecss_filter_attr()`/`safe_style_css`, which is a
 *   narrower allowlist than the theme.json schema — so a path can render for
 *   everyone yet persist only for some users.
 *
 * Both are probes rather than hardcoded property names, so a site that widens
 * `safe_style_css`, or a future core release that does, is picked up without a
 * code change here.
 */
final class ThemeJsonCapabilities {

	private const CACHE_PREFIX = 'flavor_agent_tj_cap_';

	/**
	 * One day. Written as a literal rather than `DAY_IN_SECONDS` so the class
	 * constant does not depend on WordPress being loaded at class-load time.
	 */
	private const CACHE_TTL = 86400;

	private const LEG_RENDERS  = 'renders';
	private const LEG_PERSISTS = 'persists';

	/**
	 * A syntactically boring, unambiguously safe value. A malformed probe value
	 * could be rejected by a value-level check and yield a false negative.
	 */
	private const PROBE_VALUE = '1px 1px 1px #000000';

	/**
	 * @var array<string, bool>
	 */
	private static array $memo = [];

	/**
	 * Transient keys this class has written, so `flush()` can clear them
	 * without having to reconstruct every possible runtime fingerprint.
	 *
	 * @var array<int, string>
	 */
	private static array $cache_keys = [];

	/**
	 * Whether a value written at the given style path survives sanitization and
	 * therefore renders as CSS on this site.
	 *
	 * @param array<int, string> $path Style path relative to `styles`, e.g. `[ 'typography', 'textShadow' ]`.
	 */
	public static function supports_style_path( array $path ): bool {
		return self::probe( $path, self::LEG_RENDERS );
	}

	/**
	 * Whether the current user can persist a value at the given style path.
	 *
	 * @param array<int, string> $path Style path relative to `styles`.
	 */
	public static function current_user_can_persist_style_path( array $path ): bool {
		if ( ! self::supports_style_path( $path ) ) {
			return false;
		}

		// Users with 'unfiltered_html' never have the global-styles kses filter
		// installed, so nothing strips the value on save.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'unfiltered_html' ) ) {
			return true;
		}

		return self::probe( $path, self::LEG_PERSISTS );
	}

	/**
	 * Clears memoized and cached probe results. Intended for tests and for
	 * callers that have just changed the runtime (activated a plugin, etc.).
	 */
	public static function flush(): void {
		self::$memo = [];

		if ( function_exists( 'delete_transient' ) ) {
			foreach ( self::$cache_keys as $cache_key ) {
				delete_transient( $cache_key );
			}
		}

		self::$cache_keys = [];
	}

	/**
	 * @param array<int, string> $path
	 */
	private static function probe( array $path, string $leg ): bool {
		$path = self::normalize_path( $path );

		if ( [] === $path ) {
			return false;
		}

		$key = $leg . ':' . implode( '.', $path );

		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		$result = self::cached_probe( $path, $leg, $key );

		/**
		 * Filters the result of a theme.json style-path capability probe.
		 *
		 * Hosts running patched core, or sites that widen `safe_style_css`
		 * through means this probe cannot observe, can correct the answer here.
		 *
		 * @param bool               $result Whether the path is supported.
		 * @param array<int, string> $path   Style path relative to `styles`.
		 * @param string             $leg    Either 'renders' or 'persists'.
		 */
		$result = (bool) apply_filters(
			'flavor_agent_theme_json_supports_style_path',
			$result,
			$path,
			$leg
		);

		self::$memo[ $key ] = $result;

		return $result;
	}

	/**
	 * @param array<int, string> $path
	 */
	private static function cached_probe( array $path, string $leg, string $key ): bool {
		if ( ! function_exists( 'get_transient' ) ) {
			return self::run_probe( $path, $leg );
		}

		$transient = self::CACHE_PREFIX . md5( self::runtime_fingerprint() . '|' . $key );

		if ( ! in_array( $transient, self::$cache_keys, true ) ) {
			self::$cache_keys[] = $transient;
		}

		$cached = get_transient( $transient );

		// Stored as yes/no strings: get_transient() returns false for a missing
		// entry, which is otherwise indistinguishable from a negative result.
		if ( 'yes' === $cached || 'no' === $cached ) {
			return 'yes' === $cached;
		}

		$result = self::run_probe( $path, $leg );

		set_transient( $transient, $result ? 'yes' : 'no', self::CACHE_TTL );

		return $result;
	}

	/**
	 * @param array<int, string> $path
	 */
	private static function run_probe( array $path, string $leg ): bool {
		$class = self::theme_json_class();

		if ( '' === $class ) {
			return false;
		}

		try {
			$probe = [
				'version' => self::latest_schema( $class ),
				'styles'  => self::nest( $path, self::PROBE_VALUE ),
			];

			if ( self::LEG_PERSISTS === $leg ) {
				if ( ! is_callable( [ $class, 'remove_insecure_properties' ] ) ) {
					return false;
				}

				$filtered = $class::remove_insecure_properties( $probe, 'custom' );

				return self::path_holds_probe_value( is_array( $filtered ) ? $filtered : [], $path );
			}

			$theme_json = new $class( $probe, 'custom' );

			if ( ! is_callable( [ $theme_json, 'get_raw_data' ] ) ) {
				return false;
			}

			$raw = $theme_json->get_raw_data();

			return self::path_holds_probe_value( is_array( $raw ) ? $raw : [], $path );
		} catch ( \Throwable ) {
			// Fail closed. An unsupported path is always safer than an apply
			// that silently does nothing.
			return false;
		}
	}

	/**
	 * Resolves the theme.json class actually in play. Gutenberg's class shadows
	 * core's whenever the plugin is active, including for the save-time filter
	 * (Gutenberg swaps `wp_filter_global_styles_post` for its own equivalent).
	 */
	private static function theme_json_class(): string {
		if ( class_exists( '\WP_Theme_JSON_Gutenberg' ) ) {
			return '\WP_Theme_JSON_Gutenberg';
		}

		if ( class_exists( '\WP_Theme_JSON' ) ) {
			return '\WP_Theme_JSON';
		}

		return '';
	}

	private static function latest_schema( string $theme_json_class ): int {
		try {
			$reflector = new \ReflectionClass( $theme_json_class );
		} catch ( \ReflectionException ) {
			return 3;
		}

		if ( ! $reflector->hasConstant( 'LATEST_SCHEMA' ) ) {
			return 3;
		}

		$version = $reflector->getConstant( 'LATEST_SCHEMA' );

		return is_numeric( $version ) ? (int) $version : 3;
	}

	/**
	 * Keys probe caches on the runtime that produced them, so activating or
	 * updating Gutenberg or WordPress invalidates them automatically rather
	 * than leaving a stale answer for the cache lifetime.
	 */
	private static function runtime_fingerprint(): string {
		global $wp_version;

		return implode(
			'|',
			[
				self::theme_json_class(),
				is_string( $wp_version ) ? $wp_version : '',
				defined( 'GUTENBERG_VERSION' ) ? (string) constant( 'GUTENBERG_VERSION' ) : '',
			]
		);
	}

	/**
	 * @param array<int, string> $path
	 * @return array<int, string>
	 */
	private static function normalize_path( array $path ): array {
		$normalized = [];

		foreach ( $path as $segment ) {
			if ( ! is_string( $segment ) && ! is_numeric( $segment ) ) {
				return [];
			}

			$segment = (string) $segment;

			if ( '' === $segment ) {
				return [];
			}

			$normalized[] = $segment;
		}

		return $normalized;
	}

	/**
	 * @param array<int, string> $path
	 * @return array<string, mixed>
	 */
	private static function nest( array $path, string $value ): array {
		$nested = $value;

		foreach ( array_reverse( $path ) as $segment ) {
			$nested = [ $segment => $nested ];
		}

		return is_array( $nested ) ? $nested : [];
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<int, string>   $path
	 */
	private static function path_holds_probe_value( array $data, array $path ): bool {
		$cursor = $data['styles'] ?? null;

		foreach ( $path as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return false;
			}

			$cursor = $cursor[ $segment ];
		}

		return self::PROBE_VALUE === $cursor;
	}
}
