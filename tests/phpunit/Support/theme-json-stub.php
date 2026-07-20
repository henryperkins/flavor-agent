<?php
/**
 * Test double for the theme.json implementation.
 *
 * Models the two filters that decide whether a style path is usable:
 *
 * - `sanitize()` drops leaves absent from the class's `VALID_STYLES` schema.
 *   This decides whether a value renders as CSS.
 * - `remove_insecure_properties()` additionally drops declarations that
 *   `safecss_filter_attr()`/`safe_style_css` does not allow. This runs on save,
 *   and only for users without `unfiltered_html`.
 *
 * Both allowlists default to empty, so merely loading this file cannot change
 * what any suite observes; each test opts in to what it needs and resets after.
 *
 * @package FlavorAgent\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Theme_JSON_Gutenberg' ) ) {
	class WP_Theme_JSON_Gutenberg {

		public const LATEST_SCHEMA = 3;

		/** @var array<int, string> Dot paths kept by sanitize(). */
		public static array $valid_style_paths = [];

		/** @var array<int, string> Dot paths additionally kept by remove_insecure_properties(). */
		public static array $safe_style_paths = [];

		/** @var array<string, mixed> */
		private array $data;

		/**
		 * @param array<string, mixed> $theme_json
		 */
		public function __construct( array $theme_json = [], string $origin = 'theme' ) {
			unset( $origin );

			$this->data = self::keep_only( $theme_json, self::$valid_style_paths );
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_raw_data(): array {
			return $this->data;
		}

		/**
		 * @param array<string, mixed> $theme_json
		 * @return array<string, mixed>
		 */
		public static function remove_insecure_properties( array $theme_json, string $origin = 'theme' ): array {
			unset( $origin );

			$sanitized = self::keep_only( $theme_json, self::$valid_style_paths );

			return self::keep_only( $sanitized, self::$safe_style_paths );
		}

		public static function reset(): void {
			self::$valid_style_paths = [];
			self::$safe_style_paths  = [];
		}

		/**
		 * @param array<string, mixed> $theme_json
		 * @param array<int, string>   $allowed
		 * @return array<string, mixed>
		 */
		private static function keep_only( array $theme_json, array $allowed ): array {
			$styles = $theme_json['styles'] ?? null;

			if ( ! is_array( $styles ) ) {
				return $theme_json;
			}

			$kept   = self::walk( $styles, [], $allowed );
			$result = $theme_json;

			if ( [] === $kept ) {
				unset( $result['styles'] );

				return $result;
			}

			$result['styles'] = $kept;

			return $result;
		}

		/**
		 * @param array<string, mixed> $branch
		 * @param array<int, string>   $trail
		 * @param array<int, string>   $allowed
		 * @return array<string, mixed>
		 */
		private static function walk( array $branch, array $trail, array $allowed ): array {
			$kept = [];

			foreach ( $branch as $key => $value ) {
				$path = array_merge( $trail, [ (string) $key ] );

				if ( is_array( $value ) ) {
					$nested = self::walk( $value, $path, $allowed );

					if ( [] !== $nested ) {
						$kept[ $key ] = $nested;
					}

					continue;
				}

				if ( in_array( implode( '.', $path ), $allowed, true ) ) {
					$kept[ $key ] = $value;
				}
			}

			return $kept;
		}
	}
}
