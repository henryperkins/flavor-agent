<?php

declare(strict_types=1);

namespace FlavorAgent\Tests\Support {

	final class WordPressTestState {

		public static array $global_settings = [];

		public static array $global_styles = [];

		public static array $last_remote_post = [];

		public static array $last_remote_get = [];

		public static array $remote_post_calls = [];

		public static array $remote_get_calls = [];

		public static array $remote_post_responses = [];

		public static array $remote_get_responses = [];

		public static array $last_ai_client_prompt = [];

		public static array $options = [];

		public static array $capabilities = [];

		public static array $block_templates = [];

		public static array $transients = [];

		public static array $registered_abilities = [];

		public static array $registered_ability_categories = [];

		public static array $settings_errors = [];

		/** @var array<string, array{hook: string, timestamp: int}> */
		public static array $scheduled_events = [];

		/** @var array<string, mixed> */
		public static array $updated_options = [];

		/** @var array<string> */
		public static array $cleared_cron_hooks = [];

		/** @var array<int, object> */
		public static array $posts = [];

		public static mixed $remote_post_response = [];

		public static mixed $remote_get_response = [];

		public static bool $ai_client_supported = false;

		public static mixed $ai_client_generate_text_result = '';

		public static function reset(): void {
			self::$global_settings             = [];
			self::$global_styles               = [];
			self::$last_remote_post            = [];
			self::$last_remote_get             = [];
			self::$remote_post_calls           = [];
			self::$remote_get_calls            = [];
			self::$remote_post_responses       = [];
			self::$remote_get_responses        = [];
			self::$last_ai_client_prompt       = [];
			self::$options                     = [];
			self::$capabilities                = [];
			self::$block_templates             = [];
			self::$transients                  = [];
			self::$registered_abilities        = [];
			self::$registered_ability_categories = [];
			self::$settings_errors             = [];
			self::$scheduled_events            = [];
			self::$updated_options              = [];
			self::$cleared_cron_hooks           = [];
			self::$posts                       = [];
			self::$remote_post_response        = [];
			self::$remote_get_response         = [];
			self::$ai_client_supported         = false;
			self::$ai_client_generate_text_result = '';

			\WP_Block_Type_Registry::get_instance()->reset();
			\WP_Block_Patterns_Registry::get_instance()->reset();
		}
	}
}

namespace WordPress\AI_Client {

	use FlavorAgent\Tests\Support\WordPressTestState;

	final class AI_Client {

		public static function prompt_with_wp_error( string $text ): FakePromptBuilder {
			WordPressTestState::$last_ai_client_prompt = [
				'text' => $text,
				'transport' => 'legacy_class',
			];

			return new FakePromptBuilder();
		}
	}

	final class FakePromptBuilder {

		public function using_system_instruction( string $text ): self {
			WordPressTestState::$last_ai_client_prompt['system'] = $text;

			return $this;
		}

		public function is_supported_for_text_generation(): bool {
			return WordPressTestState::$ai_client_supported;
		}

		public function generate_text(): mixed {
			return WordPressTestState::$ai_client_generate_text_result;
		}
	}
}

namespace {

	use FlavorAgent\Tests\Support\WordPressTestState;

	if ( ! class_exists( 'WP_AI_Client_Prompt_Builder' ) ) {
		class WP_AI_Client_Prompt_Builder {

			public function __call( string $name, array $arguments ) {
				switch ( $name ) {
					case 'using_system_instruction':
						WordPressTestState::$last_ai_client_prompt['system'] = (string) ( $arguments[0] ?? '' );

						return $this;
					case 'is_supported_for_text_generation':
						return WordPressTestState::$ai_client_supported;
					case 'generate_text':
						return WordPressTestState::$ai_client_generate_text_result;
				}

				throw new \BadMethodCallException( "Unknown AI client method {$name}." );
			}
		}
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		function wp_ai_client_prompt( $prompt = null ): WP_AI_Client_Prompt_Builder {
			WordPressTestState::$last_ai_client_prompt = [
				'text'      => is_string( $prompt ) ? $prompt : '',
				'transport' => 'core_function',
			];

			return new WP_AI_Client_Prompt_Builder();
		}
	}

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

	if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
		class WP_Block_Patterns_Registry {

			private static ?self $instance = null;

			/**
			 * @var array<string, array<string, mixed>>
			 */
			private array $registered = [];

			public static function get_instance(): self {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			public function register( string $pattern_name, array $pattern_properties ): void {
				$this->registered[ $pattern_name ] = array_merge( $pattern_properties, [ 'name' => $pattern_name ] );
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function get_all_registered(): array {
				return array_values( $this->registered );
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

	if ( ! function_exists( 'sanitize_url' ) ) {
		function sanitize_url( $url, array $protocols = [] ): string {
			return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
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
			WordPressTestState::$remote_post_calls[] = WordPressTestState::$last_remote_post;

			if ( [] !== WordPressTestState::$remote_post_responses ) {
				return array_shift( WordPressTestState::$remote_post_responses );
			}

			if ( empty( WordPressTestState::$remote_post_response ) ) {
				return new WP_Error( 'missing_remote_stub', 'No remote response stub configured.' );
			}

			return WordPressTestState::$remote_post_response;
		}
	}

	if ( ! function_exists( 'wp_remote_get' ) ) {
		function wp_remote_get( string $url, array $args = [] ) {
			WordPressTestState::$last_remote_get = [
				'url'  => $url,
				'args' => $args,
			];
			WordPressTestState::$remote_get_calls[] = WordPressTestState::$last_remote_get;

			if ( [] !== WordPressTestState::$remote_get_responses ) {
				return array_shift( WordPressTestState::$remote_get_responses );
			}

			if ( empty( WordPressTestState::$remote_get_response ) ) {
				return new WP_Error( 'missing_remote_stub', 'No remote response stub configured.' );
			}

			return WordPressTestState::$remote_get_response;
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

	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			if ( is_array( $value ) ) {
				return array_map( 'wp_unslash', $value );
			}

			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}

	if ( ! function_exists( 'add_settings_error' ) ) {
		function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
			WordPressTestState::$settings_errors[] = [
				'setting' => $setting,
				'code'    => $code,
				'message' => $message,
				'type'    => $type,
			];
		}
	}

	if ( ! function_exists( 'get_settings_errors' ) ) {
		function get_settings_errors( string $setting = '', bool $sanitize = false ): array {
			if ( ! empty( $_GET['settings-updated'] ) ) {
				$transient_errors = get_transient( 'settings_errors' );

				if ( is_array( $transient_errors ) && [] !== $transient_errors ) {
					WordPressTestState::$settings_errors = array_merge(
						WordPressTestState::$settings_errors,
						$transient_errors
					);
					delete_transient( 'settings_errors' );
				}
			}

			if ( '' === $setting ) {
				return WordPressTestState::$settings_errors;
			}

			return array_values(
				array_filter(
					WordPressTestState::$settings_errors,
					static fn ( array $details ): bool => ( $details['setting'] ?? '' ) === $setting
				)
			);
		}
	}

	if ( ! function_exists( 'settings_errors' ) ) {
		function settings_errors( string $setting = '', bool $sanitize = false, bool $hide_on_update = false ): void {
			if ( $hide_on_update && ! empty( $_GET['settings-updated'] ) ) {
				return;
			}

			$settings_errors = get_settings_errors( $setting, $sanitize );

			if ( [] === $settings_errors ) {
				return;
			}

			foreach ( $settings_errors as $details ) {
				$type = (string) ( $details['type'] ?? 'error' );

				if ( 'updated' === $type ) {
					$type = 'success';
				}

				printf(
					"<div class='notice notice-%s settings-error'><p><strong>%s</strong></p></div>\n",
					htmlspecialchars( $type, ENT_QUOTES, 'UTF-8' ),
					htmlspecialchars( (string) ( $details['message'] ?? '' ), ENT_QUOTES, 'UTF-8' )
				);
			}
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $post_id = null ) {
			if ( $post_id === null ) {
				return null;
			}

			$id = (int) ( is_object( $post_id ) ? ( $post_id->ID ?? 0 ) : $post_id );

			return WordPressTestState::$posts[ $id ] ?? null;
		}
	}

	if ( ! function_exists( 'parse_blocks' ) ) {
		function parse_blocks( string $content ): array {
			$blocks  = [];
			$pattern = '/<!--\s+wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)\s*(\{[^}]*\})?\s*(\/)?-->/';
			$offset  = 0;

			while ( preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				$full_match = $match[0][0];
				$match_pos  = $match[0][1];
				$block_name = 'core/' . $match[1][0];

				if ( str_contains( $match[1][0], '/' ) ) {
					$block_name = $match[1][0];
				}

				$attrs_json   = $match[2][0] ?? '';
				$self_closing = ! empty( $match[3][0] );

				$attrs = [];
				if ( $attrs_json !== '' ) {
					$decoded = json_decode( $attrs_json, true );
					if ( is_array( $decoded ) ) {
						$attrs = $decoded;
					}
				}

				if ( $self_closing ) {
					$blocks[] = [
						'blockName'    => $block_name,
						'attrs'        => $attrs,
						'innerBlocks'  => [],
						'innerHTML'    => '',
						'innerContent' => [],
					];
					$offset = $match_pos + strlen( $full_match );
				} else {
					$close_tag = '<!-- /wp:' . $match[1][0] . ' -->';
					$close_pos = strpos( $content, $close_tag, $match_pos + strlen( $full_match ) );

					if ( $close_pos !== false ) {
						$inner_html = substr(
							$content,
							$match_pos + strlen( $full_match ),
							$close_pos - ( $match_pos + strlen( $full_match ) )
						);

						$blocks[] = [
							'blockName'    => $block_name,
							'attrs'        => $attrs,
							'innerBlocks'  => parse_blocks( $inner_html ),
							'innerHTML'    => $inner_html,
							'innerContent' => [ $inner_html ],
						];

						$offset = $close_pos + strlen( $close_tag );
					} else {
						$offset = $match_pos + strlen( $full_match );
					}
				}
			}

			return $blocks;
		}
	}

	if ( ! function_exists( 'str_starts_with' ) ) {
		function str_starts_with( string $haystack, string $needle ): bool {
			return strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool {
			WordPressTestState::$options[ $name ] = $value;
			WordPressTestState::$updated_options[ $name ] = $value;

			return true;
		}
	}

	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
			WordPressTestState::$scheduled_events[ $hook ] = [
				'hook'      => $hook,
				'timestamp' => $timestamp,
				'args'      => $args,
			];

			return true;
		}
	}

	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		function wp_next_scheduled( string $hook, array $args = [] ) {
			if ( isset( WordPressTestState::$scheduled_events[ $hook ] ) ) {
				return WordPressTestState::$scheduled_events[ $hook ]['timestamp'];
			}

			return false;
		}
	}

	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
		function wp_clear_scheduled_hook( string $hook, array $args = [] ): int {
			WordPressTestState::$cleared_cron_hooks[] = $hook;
			unset( WordPressTestState::$scheduled_events[ $hook ] );

			return 1;
		}
	}

	require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

	WordPressTestState::reset();
}
