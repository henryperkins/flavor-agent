<?php

declare(strict_types=1);

namespace FlavorAgent\Tests\Support {

	final class WordPressTestState {

		public static array $global_settings = [];

		public static array $global_styles = [];

		public static array $last_remote_post = [];

		public static array $options = [];

		public static array $capabilities = [];

		public static array $block_templates = [];

		public static array $transients = [];

		public static array $registered_abilities = [];

		public static array $registered_ability_categories = [];

		public static mixed $remote_post_response = [];

		public static function reset(): void {
			self::$global_settings             = [];
			self::$global_styles               = [];
			self::$last_remote_post            = [];
			self::$options                     = [];
			self::$capabilities                = [];
			self::$block_templates             = [];
			self::$transients                  = [];
			self::$registered_abilities        = [];
			self::$registered_ability_categories = [];
			self::$remote_post_response        = [];

			\WP_Block_Type_Registry::get_instance()->reset();
		}
	}
}

namespace {

	use FlavorAgent\Tests\Support\WordPressTestState;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {

			/**
			 * @var array<string, string[]>
			 */
			public array $errors = [];

			/**
			 * @var array<string, mixed>
			 */
			public array $error_data = [];

			public function __construct( string $code = '', string $message = '', $data = null ) {
				if ( '' === $code ) {
					return;
				}

				$this->errors[ $code ] = [ $message ];

				if ( null !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}

			public function get_error_code(): string {
				$code = array_key_first( $this->errors );

				return is_string( $code ) ? $code : '';
			}

			public function get_error_message( string $code = '' ): string {
				$resolved_code = '' !== $code ? $code : $this->get_error_code();

				return $this->errors[ $resolved_code ][0] ?? '';
			}

			public function get_error_data( string $code = '' ) {
				$resolved_code = '' !== $code ? $code : $this->get_error_code();

				return $this->error_data[ $resolved_code ] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {

			/**
			 * @var array<string, mixed>
			 */
			private array $params = [];

			public function __construct( string $method = 'GET', string $route = '/' ) {
			}

			public function get_param( string $key ) {
				return $this->params[ $key ] ?? null;
			}

			public function has_param( string $key ): bool {
				return array_key_exists( $key, $this->params );
			}

			public function set_param( string $key, $value ): void {
				$this->params[ $key ] = $value;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		class WP_REST_Response {

			/**
			 * @var mixed
			 */
			private $data;

			private int $status;

			public function __construct( $data = null, int $status = 200 ) {
				$this->data   = $data;
				$this->status = $status;
			}

			public function get_data() {
				return $this->data;
			}

			public function get_status(): int {
				return $this->status;
			}
		}
	}

	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		class WP_Block_Type_Registry {

			private static ?self $instance = null;

			/**
			 * @var array<string, object>
			 */
			private array $registered = [];

			public static function get_instance(): self {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			public function get_registered( string $block_name ): ?object {
				return $this->registered[ $block_name ] ?? null;
			}

			public function register( string $block_name, array $args ): void {
				$block_type = (object) $args;

				if ( array_key_exists( 'allowedBlocks', $args ) ) {
					$block_type->allowed_blocks = $args['allowedBlocks'];
					unset( $block_type->allowedBlocks );
				}

				if ( array_key_exists( 'apiVersion', $args ) ) {
					$block_type->api_version = $args['apiVersion'];
					unset( $block_type->apiVersion );
				}

				$this->registered[ $block_name ] = $block_type;
			}

			public function reset(): void {
				$this->registered = [];
			}
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) {
			return WordPressTestState::$options[ $name ] ?? $default;
		}
	}

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( string $name ) {
			return array_key_exists( $name, WordPressTestState::$transients )
				? WordPressTestState::$transients[ $name ]
				: false;
		}
	}

	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( string $name, $value, int $expiration = 0 ): bool {
			WordPressTestState::$transients[ $name ] = $value;

			return true;
		}
	}

	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( string $name ): bool {
			unset( WordPressTestState::$transients[ $name ] );

			return true;
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $capability, ...$args ): bool {
			return (bool) ( WordPressTestState::$capabilities[ $capability ] ?? false );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return $value instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( string $key ): string {
			return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $key ) ?? '' );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string {
			return trim(
				preg_replace(
					'/\s+/u',
					' ',
					wp_strip_all_tags( (string) $value )
				) ?? ''
			);
		}
	}

	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		function sanitize_textarea_field( $value ): string {
			return trim(
				preg_replace(
					'/[^\S\r\n]+/u',
					' ',
					wp_strip_all_tags( (string) $value )
				) ?? ''
			);
		}
	}

	if ( ! function_exists( 'wp_get_global_settings' ) ) {
		function wp_get_global_settings(): array {
			return WordPressTestState::$global_settings;
		}
	}

	if ( ! function_exists( 'wp_get_global_styles' ) ) {
		function wp_get_global_styles(): array {
			return WordPressTestState::$global_styles;
		}
	}

	if ( ! function_exists( 'get_block_templates' ) ) {
		function get_block_templates( array $query = [], string $template_type = 'wp_template' ): array {
			$templates = WordPressTestState::$block_templates[ $template_type ] ?? [];
			$result    = [];

			foreach ( $templates as $template ) {
				$template = is_object( $template ) ? $template : (object) $template;

				if ( isset( $query['area'] ) && (string) ( $template->area ?? '' ) !== (string) $query['area'] ) {
					continue;
				}

				if (
					! empty( $query['slug__in'] ) &&
					! in_array( (string) ( $template->slug ?? '' ), (array) $query['slug__in'], true )
				) {
					continue;
				}

				if ( isset( $query['wp_id'] ) && (int) ( $template->wp_id ?? 0 ) !== (int) $query['wp_id'] ) {
					continue;
				}

				$result[] = $template;
			}

			return $result;
		}
	}

	if ( ! function_exists( 'get_block_template' ) ) {
		function get_block_template( string $id, string $template_type = 'wp_template' ) {
			foreach ( get_block_templates( [], $template_type ) as $template ) {
				if ( (string) ( $template->id ?? '' ) === $id ) {
					return $template;
				}
			}

			return null;
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value ) {
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( string $id, array $args ): void {
			WordPressTestState::$registered_abilities[ $id ] = $args;
		}
	}

	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $id, array $args ): void {
			WordPressTestState::$registered_ability_categories[ $id ] = $args;
		}
	}

	if ( ! function_exists( 'wp_remote_post' ) ) {
		function wp_remote_post( string $url, array $args = [] ) {
			WordPressTestState::$last_remote_post = [
				'url'  => $url,
				'args' => $args,
			];

			if ( empty( WordPressTestState::$remote_post_response ) ) {
				return new WP_Error( 'missing_remote_stub', 'No remote response stub configured.' );
			}

			return WordPressTestState::$remote_post_response;
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( $response ): string {
			return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( $response ): int {
			if ( ! is_array( $response ) ) {
				return 0;
			}

			return (int) ( $response['response']['code'] ?? 0 );
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text ): string {
			return strip_tags( $text );
		}
	}

	require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

	WordPressTestState::reset();
}
