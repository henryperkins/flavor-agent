<?php

declare(strict_types=1);

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		if ( ! defined( 'FLAVOR_AGENT_TESTS_RUNNING' ) ) {
			exit;
		}

		define( 'ABSPATH', __DIR__ . '/' );
	}
}

namespace FlavorAgent\Tests\Support {

	final class WordPressTestState {

		public static array $global_settings = [];

		public static array $global_styles = [];

		public static array $active_theme = [];

		public static array $last_remote_post = [];

		public static array $last_remote_get = [];

		public static array $remote_post_calls = [];

		public static array $remote_get_calls = [];

		public static array $remote_post_responses = [];

		public static array $remote_get_responses = [];

		public static array $last_ai_client_prompt = [];

		/** @var array<int, array{prompt: string, options: array<string, mixed>}> */
		public static array $ai_service_calls = [];

		public static ?\Throwable $ai_service_call_throws = null;

		public static string $wpai_formatted_guidelines = '';

		/** @var array<int, array{categories: array<int, string>, blockName: string|null}> */
		public static array $wpai_guideline_calls = [];

		public static array $last_http_request_args = [];

		public static array $options = [];

		/** @var array<string, array<string, mixed>> */
		public static array $connectors = [];

		/** @var array<string, string> */
		public static array $connector_api_errors = [];

		public static array $capabilities = [];

		public static array $block_templates = [];

		public static array $transients = [];

		public static array $transient_expirations = [];

		public static array $registered_abilities = [];

		public static array $registered_ability_categories = [];

		public static array $settings_errors = [];

		/** @var array<string, array<string, mixed>> */
		public static array $rest_routes = [];

		/** @var array<string, callable> */
		public static array $activation_hooks = [];

		/** @var array<string, callable> */
		public static array $deactivation_hooks = [];

		/** @var array<string, array<string, mixed>> */
		public static array $registered_block_pattern_categories = [];

		/** @var array<string, array<string, mixed>> */
		public static array $scheduled_events = [];

		/** @var array<string, mixed> */
		public static array $updated_options = [];

		/** @var array<string> */
		public static array $cleared_cron_hooks = [];

		/** @var array<int, object> */
		public static array $posts = [];

		/** @var array<string, array<string, mixed>> */
		public static array $registered_post_types = [];

		/** @var array<string, array<string, mixed>> */
		public static array $registered_taxonomies = [];

		/** @var array<int, array<string, mixed>> */
		public static array $get_posts_calls = [];

		/** @var array<int, array<string, mixed>> */
		public static array $post_meta = [];

		/** @var array<string, array<int, array<string, mixed>>> */
		public static array $db_tables = [];

		/** @var array<int, string> */
		public static array $db_queries = [];

		/** @var array<string, array<int, array<int, array{callback: callable, accepted_args: int}>>> */
		public static array $filters = [];

		public static int $db_insert_id = 0;

		public static int $current_user_id = 0;

		public static ?object $current_screen = null;

		public static mixed $remote_post_response = [];

		public static mixed $remote_get_response = [];

		public static bool $ai_client_supported = false;

		/** @var array<string, bool> */
		public static array $ai_client_provider_support = [];

		/** @var array<string, bool> */
		public static array $ai_client_feature_support = [];

		public static mixed $ai_client_generate_text_result = '';

		public static ?object $current_post = null;

		/**
		 * @param array<string, string> $errors
		 */
		public static function set_connector_api_errors( array $errors ): void {
			self::$connector_api_errors = $errors;
		}

		public static function get_connector_api_error( string $function_name ): ?string {
			return self::$connector_api_errors[ $function_name ] ?? null;
		}

		/**
		 * @param array<string, mixed> $prompt_state
		 */
		public static function ai_client_prompt_supports_text_generation( array $prompt_state ): bool {
			if (
				array_key_exists( 'reasoning', self::$ai_client_feature_support )
				&& ! self::$ai_client_feature_support['reasoning']
				&& isset( $prompt_state['reasoning'] )
				&& '' !== (string) $prompt_state['reasoning']
			) {
				return false;
			}

			if (
				array_key_exists( 'json_schema', self::$ai_client_feature_support )
				&& ! self::$ai_client_feature_support['json_schema']
				&& is_array( $prompt_state['json_schema'] ?? null )
			) {
				return false;
			}

			$provider = $prompt_state['provider'] ?? '';

			if (
				is_string( $provider )
				&& '' !== $provider
				&& array_key_exists( $provider, self::$ai_client_provider_support )
			) {
				return (bool) self::$ai_client_provider_support[ $provider ];
			}

			if ( self::$ai_client_supported ) {
				return true;
			}

			return null !== self::pending_chat_output_text();
		}

		/**
		 * Compatibility bridge for tests written before Workstream C of the WP 7.0
		 * overlap remediation. When a test seeds an Azure-shaped chat response in
		 * $remote_post_response (or $remote_post_responses), translate the inner
		 * `output_text` into the AI Client mock surface so tests that previously
		 * exercised the direct-HTTP chat path now exercise the Connectors path.
		 */
		public static function pending_chat_output_text(): ?string {
			if ( isset( self::$remote_post_response['body'] ) && is_string( self::$remote_post_response['body'] ) ) {
				$decoded = json_decode( self::$remote_post_response['body'], true );

				if ( self::is_chat_output_text_payload( $decoded ) ) {
					return (string) $decoded['output_text'];
				}
			}

			foreach ( self::$remote_post_responses as $queued ) {
				if ( is_array( $queued ) && isset( $queued['body'] ) && is_string( $queued['body'] ) ) {
					$decoded = json_decode( $queued['body'], true );

					if ( self::is_chat_output_text_payload( $decoded ) ) {
						return (string) $decoded['output_text'];
					}
				}
			}

			return null;
		}

		/**
		 * Consume the next chat-shaped response from the queue and append a synthetic
		 * remote_post_call so tests that assert on $remote_post_calls[N]['args']['body']
		 * continue to work after Workstream C moved chat onto the AI Client.
		 */
		public static function consume_pending_chat_response_for_ai_client( object $prompt_state ): ?string {
			if ( isset( self::$remote_post_response['body'] ) && is_string( self::$remote_post_response['body'] ) ) {
				$decoded = json_decode( self::$remote_post_response['body'], true );

				if ( self::is_chat_output_text_payload( $decoded ) ) {
					self::record_synthetic_chat_remote_post_call( $prompt_state );

					return (string) $decoded['output_text'];
				}
			}

			foreach ( self::$remote_post_responses as $index => $queued ) {
				if ( ! is_array( $queued ) || ! isset( $queued['body'] ) || ! is_string( $queued['body'] ) ) {
					continue;
				}

				$decoded = json_decode( $queued['body'], true );

				if ( ! self::is_chat_output_text_payload( $decoded ) ) {
					continue;
				}

				array_splice( self::$remote_post_responses, $index, 1 );
				self::record_synthetic_chat_remote_post_call( $prompt_state );

				return (string) $decoded['output_text'];
			}

			return null;
		}

		private static function is_chat_output_text_payload( mixed $decoded ): bool {
			return is_array( $decoded )
				&& isset( $decoded['output_text'] )
				&& is_string( $decoded['output_text'] );
		}

		private static function record_synthetic_chat_remote_post_call( object $prompt_state ): void {
			$prompt = (array) $prompt_state;

			$body = wp_json_encode(
				array_filter(
					[
						'model'        => 'provider-managed',
						'instructions' => isset( $prompt['system'] ) ? (string) $prompt['system'] : '',
						'input'        => isset( $prompt['text'] ) ? (string) $prompt['text'] : '',
						'reasoning'    => isset( $prompt['reasoning'] ) && '' !== $prompt['reasoning']
							? [ 'effort' => (string) $prompt['reasoning'] ]
							: null,
						'text'         => isset( $prompt['json_schema'] ) && is_array( $prompt['json_schema'] )
							? [
								'format' => [
									'type'   => 'json_schema',
									'name'   => 'flavor_agent_response',
									'schema' => $prompt['json_schema'],
									'strict' => true,
								],
							]
							: null,
					]
				)
			);

			self::$last_remote_post = [
				'url'  => 'flavor-agent://wordpress-ai-client/responses',
				'args' => [
					'body'    => is_string( $body ) ? $body : '',
					'headers' => [],
				],
			];
			self::$remote_post_calls[] = self::$last_remote_post;
		}

		public static function reset(): void {
			self::$global_settings             = [];
			self::$global_styles               = [];
			self::$active_theme                = [];
			self::$last_remote_post            = [];
			self::$last_remote_get             = [];
			self::$remote_post_calls           = [];
			self::$remote_get_calls            = [];
			self::$remote_post_responses       = [];
			self::$remote_get_responses        = [];
			self::$last_ai_client_prompt       = [];
			self::$ai_service_calls            = [];
			self::$ai_service_call_throws      = null;
			self::$wpai_formatted_guidelines   = '';
			self::$wpai_guideline_calls        = [];
			self::$last_http_request_args      = [];
			self::$options                     = [];
			self::$connectors                  = [];
			self::$connector_api_errors        = [];
			self::$capabilities                = [];
			self::$block_templates             = [];
			self::$transients                  = [];
			self::$transient_expirations       = [];
			self::$registered_abilities        = [];
			self::$registered_ability_categories = [];
			self::$settings_errors             = [];
			self::$rest_routes                 = [];
			self::$activation_hooks            = [];
			self::$deactivation_hooks          = [];
			self::$registered_block_pattern_categories = [];
			self::$scheduled_events            = [];
			self::$updated_options              = [];
			self::$cleared_cron_hooks           = [];
			self::$posts                       = [];
			self::$registered_post_types       = [];
			self::$registered_taxonomies       = [];
			self::$get_posts_calls             = [];
			self::$post_meta                   = [];
			self::$db_tables                   = [];
			self::$db_queries                  = [];
			self::$filters                     = [];
			self::$db_insert_id                = 0;
			self::$current_user_id             = 0;
			self::$current_screen              = null;
			self::$remote_post_response        = [];
			self::$remote_get_response         = [];
			self::$ai_client_supported         = false;
			self::$ai_client_provider_support  = [];
			self::$ai_client_feature_support   = [];
			self::$ai_client_generate_text_result = '';
			self::$current_post                = null;

			$GLOBALS['wp_settings_fields']   = [];
			$GLOBALS['wp_settings_sections'] = [];
			$GLOBALS['wp_registered_settings'] = [];

			\WP_Block_Type_Registry::get_instance()->reset();
			\WP_Block_Patterns_Registry::get_instance()->reset();
		}
	}
}

namespace WordPress\AI_Client\Builders\Exception {

	if ( ! class_exists( 'WordPress\\AI_Client\\Builders\\Exception\\Prompt_Prevented_Exception' ) ) {
		final class Prompt_Prevented_Exception extends \RuntimeException {}
	}
}

namespace WordPress\AiClient\Providers\Models\DTO {

	if ( ! class_exists( 'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig' ) ) {
		final class ModelConfig {

			/**
			 * @param array<string, mixed> $config
			 */
			public function __construct( private array $config = [] ) {}

			/**
			 * @param array<string, mixed> $config
			 */
			public static function fromArray( array $config ): self {
				return new self( $config );
			}

			/**
			 * @return array<string, mixed>
			 */
			public function toArray(): array {
				return $this->config;
			}
		}
	}
}

namespace WordPress\AI {

	use FlavorAgent\Tests\Support\WordPressTestState;

	final class FakeAIService {

		/**
		 * @param array<string, mixed> $options
		 */
		public function create_textgen_prompt( ?string $prompt = null, array $options = [] ): \WP_AI_Client_Prompt_Builder {
			if ( null !== WordPressTestState::$ai_service_call_throws ) {
				throw WordPressTestState::$ai_service_call_throws;
			}

			WordPressTestState::$ai_service_calls[] = [
				'prompt'  => is_string( $prompt ) ? $prompt : '',
				'options' => $options,
			];

			$builder = \wp_ai_client_prompt( $prompt );

			if (
				isset( $options['system_instruction'] )
				&& is_callable( [ $builder, 'using_system_instruction' ] )
			) {
				$builder = $builder->using_system_instruction( (string) $options['system_instruction'] );
			}

			return $builder;
		}
	}

	if ( ! function_exists( 'WordPress\\AI\\get_ai_service' ) ) {
		function get_ai_service(): FakeAIService {
			return new FakeAIService();
		}
	}

	if ( ! function_exists( 'WordPress\\AI\\format_guidelines_for_prompt' ) ) {
		function format_guidelines_for_prompt( array $categories, ?string $block_name = null ): string {
			WordPressTestState::$wpai_guideline_calls[] = [
				'categories' => array_values( array_map( 'strval', $categories ) ),
				'blockName'  => $block_name,
			];

			return WordPressTestState::$wpai_formatted_guidelines;
		}
	}

	if ( ! function_exists( 'WordPress\\AI\\has_ai_credentials' ) ) {
		function has_ai_credentials(): bool {
			$connectors      = \function_exists( 'wp_get_connectors' ) ? \wp_get_connectors() : [];
			$has_credentials = false;

			foreach ( $connectors as $connector_data ) {
				if ( ! is_array( $connector_data ) || 'ai_provider' !== (string) ( $connector_data['type'] ?? '' ) ) {
					continue;
				}

				$auth         = is_array( $connector_data['authentication'] ?? null ) ? $connector_data['authentication'] : [];
				$setting_name = is_string( $auth['setting_name'] ?? null ) ? $auth['setting_name'] : '';

				if ( '' !== $setting_name && '' !== (string) \get_option( $setting_name, '' ) ) {
					$has_credentials = true;
					break;
				}
			}

			return (bool) \apply_filters( 'wpai_has_ai_credentials', $has_credentials, $connectors );
		}
	}

	if ( ! function_exists( 'WordPress\\AI\\has_valid_ai_credentials' ) ) {
		function has_valid_ai_credentials(): bool {
			if ( ! has_ai_credentials() ) {
				return false;
			}

			$valid = \apply_filters( 'wpai_pre_has_valid_credentials_check', null );

			if ( null !== $valid ) {
				return (bool) $valid;
			}

			return \wp_ai_client_prompt( 'Test' )->is_supported_for_text_generation();
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

		public function using_provider( string $provider ): self {
			WordPressTestState::$last_ai_client_prompt['provider'] = $provider;

			return $this;
		}

		public function using_reasoning_effort( $reasoning ): self {
			WordPressTestState::$last_ai_client_prompt['reasoning'] = is_array( $reasoning )
				? (string) ( $reasoning['effort'] ?? '' )
				: (string) $reasoning;

			return $this;
		}

		public function using_reasoning( $reasoning ): self {
			WordPressTestState::$last_ai_client_prompt['reasoning'] = is_array( $reasoning )
				? (string) ( $reasoning['effort'] ?? '' )
				: (string) $reasoning;

			return $this;
		}

		public function as_json_response( ?array $schema ): self {
			WordPressTestState::$last_ai_client_prompt['json_schema'] = is_array( $schema )
				? $schema
				: null;

			return $this;
		}

		public function is_supported_for_text_generation(): bool {
			return WordPressTestState::ai_client_prompt_supports_text_generation(
				WordPressTestState::$last_ai_client_prompt
			);
		}

		public function generate_text(): mixed {
			WordPressTestState::$last_http_request_args = apply_filters(
				'http_request_args',
				[ 'timeout' => 30 ],
				'https://api.openai.com/v1/responses'
			);

			$explicit = WordPressTestState::$ai_client_generate_text_result;

			if ( '' !== $explicit && null !== $explicit ) {
				return $explicit;
			}

			$translated = \FlavorAgent\Tests\Support\WordPressTestState::consume_pending_chat_response_for_ai_client(
				(object) WordPressTestState::$last_ai_client_prompt
			);

			return null !== $translated ? $translated : $explicit;
		}

		public function generate_text_result(): mixed {
			return $this->generate_text();
		}
	}
}

namespace {

	use FlavorAgent\Tests\Support\WordPressTestState;

	if ( ! class_exists( 'WP_AI_Client_Prompt_Builder' ) ) {
		class WP_AI_Client_Prompt_Builder {

			/**
			 * @var array<string, mixed>
			 */
			private array $state = [];

			/**
			 * @param array<string, mixed> $state
			 */
			public function __construct( array $state = [] ) {
				$this->state = $state;
				$this->sync_state();
			}

			public function __call( string $name, array $arguments ) {
				switch ( $name ) {
					case 'using_system_instruction':
						$this->state['system'] = (string) ( $arguments[0] ?? '' );
						$this->sync_state();

						return $this;
					case 'using_provider':
						$this->state['provider'] = (string) ( $arguments[0] ?? '' );
						$this->sync_state();

						return $this;
					case 'using_reasoning_effort':
					case 'using_reasoning':
						$reasoning = $arguments[0] ?? '';

						$this->state['reasoning'] = is_array( $reasoning )
							? (string) ( $reasoning['effort'] ?? '' )
							: (string) $reasoning;
						$this->sync_state();

						return $this;
					case 'using_model_config':
						$config = $arguments[0] ?? null;

						if ( is_object( $config ) && is_callable( [ $config, 'toArray' ] ) ) {
							$config = $config->toArray();
						}

						if ( is_array( $config ) ) {
							$this->state['model_config']  = $config;
							$this->state['customOptions'] = is_array( $config['customOptions'] ?? null )
								? $config['customOptions']
								: [];
						}
						$this->sync_state();

						return $this;
					case 'as_json_response':
						$this->state['json_schema'] = is_array( $arguments[0] ?? null )
							? $arguments[0]
							: null;
						$this->sync_state();

						return $this;
					case 'is_supported_for_text_generation':
						$this->sync_state();

						if ( (bool) apply_filters( 'wp_ai_client_prevent_prompt', false, $this ) ) {
							return false;
						}

						return WordPressTestState::ai_client_prompt_supports_text_generation( $this->state );
					case 'generate_text':
					case 'generate_text_result':
						$this->sync_state();
						WordPressTestState::$last_http_request_args = apply_filters(
							'http_request_args',
							[ 'timeout' => 30 ],
							'https://api.openai.com/v1/responses'
						);

						if ( (bool) apply_filters( 'wp_ai_client_prevent_prompt', false, $this ) ) {
							throw new \WordPress\AI_Client\Builders\Exception\Prompt_Prevented_Exception(
								'Prompt execution was prevented by a filter.'
							);
						}

						$explicit = WordPressTestState::$ai_client_generate_text_result;

						if ( '' !== $explicit && null !== $explicit ) {
							return $explicit;
						}

						$translated = WordPressTestState::consume_pending_chat_response_for_ai_client(
							(object) $this->state
						);

						return null !== $translated ? $translated : $explicit;
					}

					throw new \BadMethodCallException(
						sprintf(
							'Unknown AI client method %s.',
							esc_html( sanitize_text_field( $name ) )
						)
					);
				}

			private function sync_state(): void {
				WordPressTestState::$last_ai_client_prompt = $this->state;
			}
		}
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		function wp_ai_client_prompt( $prompt = null ): WP_AI_Client_Prompt_Builder {
			return new WP_AI_Client_Prompt_Builder(
				[
					'text'      => is_string( $prompt ) ? $prompt : '',
					'transport' => 'core_function',
				]
			);
		}
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

			private string $method;

			private string $route;

			public function __construct( string $method = 'GET', string $route = '/' ) {
				$this->method = strtoupper( $method );
				$this->route  = $route;
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

			public function get_method(): string {
				return $this->method;
			}

			public function get_route(): string {
				return $this->route;
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

	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! class_exists( 'wpdb' ) ) {
		class wpdb {

			public string $prefix = 'wp_';

			public int $insert_id = 0;

			public function get_charset_collate(): string {
				return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, ...$args ): string {
				$flat_args = [];

				foreach ( $args as $arg ) {
					if ( is_array( $arg ) ) {
						$flat_args = array_merge( $flat_args, $arg );
					} else {
						$flat_args[] = $arg;
					}
				}

				foreach ( $flat_args as $arg ) {
					if ( ! preg_match( '/%[sdi]/', $query, $matches ) ) {
						break;
					}

					$placeholder = (string) ( $matches[0] ?? '%s' );
					if ( '%d' === $placeholder ) {
						$replacement = (string) (int) $arg;
					} elseif ( '%i' === $placeholder ) {
						$replacement = str_replace( '`', '``', (string) $arg );
					} else {
						$replacement = "'" . str_replace( "'", "\\'", (string) $arg ) . "'";
					}

					$query = preg_replace(
						'/' . preg_quote( $placeholder, '/' ) . '/',
						$replacement,
						$query,
						1
					) ?? $query;
				}

				return $query;
			}

			public function query( string $query ) {
				WordPressTestState::$db_queries[] = $query;

				if ( preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $query, $matches ) ) {
					$table = (string) ( $matches[1] ?? '' );

					if ( '' !== $table && ! isset( WordPressTestState::$db_tables[ $table ] ) ) {
						WordPressTestState::$db_tables[ $table ] = [];
					}
				}

				if ( preg_match( '/DROP TABLE IF EXISTS\s+([^\s]+)/i', $query, $matches ) ) {
					$table = (string) ( $matches[1] ?? '' );
					unset( WordPressTestState::$db_tables[ $table ] );

					return 1;
				}

				if ( preg_match( '/DELETE FROM\s+([^\s]+)\s+WHERE\s+created_at\s*<\s*\'([^\']+)\'/i', $query, $matches ) ) {
					$table  = (string) ( $matches[1] ?? '' );
					$cutoff = (string) ( $matches[2] ?? '' );

					if ( isset( WordPressTestState::$db_tables[ $table ] ) ) {
						$before_count = count( WordPressTestState::$db_tables[ $table ] );
						WordPressTestState::$db_tables[ $table ] = array_values(
							array_filter(
								WordPressTestState::$db_tables[ $table ],
								static fn ( array $row ): bool => (string) ( $row['created_at'] ?? '' ) >= $cutoff
							)
						);

						return $before_count - count( WordPressTestState::$db_tables[ $table ] );
					}

					return 0;
				}

				return 1;
			}

			public function insert( string $table, array $data, array $format = [] ) {
				WordPressTestState::$db_insert_id += 1;
				$row = array_merge(
					[
						'id' => WordPressTestState::$db_insert_id,
					],
					$data
				);

				if ( ! isset( WordPressTestState::$db_tables[ $table ] ) ) {
					WordPressTestState::$db_tables[ $table ] = [];
				}

				WordPressTestState::$db_tables[ $table ][] = $row;
				$this->insert_id = WordPressTestState::$db_insert_id;

				return 1;
			}

			public function update(
				string $table,
				array $data,
				array $where,
				array $format = [],
				array $where_format = []
			) {
				if ( ! isset( WordPressTestState::$db_tables[ $table ] ) ) {
					return 0;
				}

				$updated = 0;

				foreach ( WordPressTestState::$db_tables[ $table ] as $index => $row ) {
					if ( ! $this->row_matches( $row, $where ) ) {
						continue;
					}

					WordPressTestState::$db_tables[ $table ][ $index ] = array_merge( $row, $data );
					++$updated;
				}

				return $updated;
			}

			public function get_row( string $query, string $output = OBJECT ) {
				$results = $this->get_results( $query, $output );

				return $results[0] ?? null;
			}

				public function get_var( string $query ) {
					WordPressTestState::$db_queries[] = $query;

					if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/i", $query, $matches ) ) {
					$table = stripslashes( (string) ( $matches[1] ?? '' ) );

						return array_key_exists( $table, WordPressTestState::$db_tables )
							? $table
							: null;
					}

					if ( preg_match( '/SELECT\s+COUNT\(\*\)\s+FROM\s+/i', $query ) ) {
						$select_all_query = preg_replace(
							'/SELECT\s+COUNT\(\*\)/i',
							'SELECT *',
							$query,
							1
						) ?? $query;

						return count( $this->get_results( $select_all_query, ARRAY_A ) );
					}

					return null;
				}

			public function get_results( string $query, string $output = OBJECT ): array {
				WordPressTestState::$db_queries[] = $query;

				if ( ! preg_match( '/FROM\s+([^\s]+)/i', $query, $matches ) ) {
					return [];
				}

					$table = (string) ( $matches[1] ?? '' );
					$rows  = array_values( WordPressTestState::$db_tables[ $table ] ?? [] );
					$all_rows = $rows;
					$has_entity_pairs = false;

				if ( preg_match( '/\b1\s*=\s*0\b/', $query ) ) {
					$rows = [];
				}

				if ( preg_match( "/document_scope_key\s*=\s*'([^']*)'/i", $query, $matches ) ) {
					$scope_key = stripslashes( (string) ( $matches[1] ?? '' ) );
					$rows      = array_values(
						array_filter(
							$rows,
							static fn ( array $row ): bool => (string) ( $row['document_scope_key'] ?? '' ) === $scope_key
						)
					);
				}

					if ( preg_match( "/surface\s*=\s*'([^']*)'/i", $query, $matches ) ) {
						$surface = stripslashes( (string) ( $matches[1] ?? '' ) );
						$rows    = array_values(
							array_filter(
							$rows,
							static fn ( array $row ): bool => (string) ( $row['surface'] ?? '' ) === $surface
						)
					);
				}

				if (
					preg_match_all(
						"/entity_type\s*=\s*'([^']*)'\s+AND\s+entity_ref\s*=\s*'([^']*)'/i",
						$query,
						$matches,
						PREG_SET_ORDER
					)
				) {
					$entity_pairs = array_values(
						array_filter(
							array_map(
								static fn ( array $match ): array => [
									'entity_type' => stripslashes( (string) ( $match[1] ?? '' ) ),
									'entity_ref'  => stripslashes( (string) ( $match[2] ?? '' ) ),
								],
								$matches
							),
							static fn ( array $pair ): bool => '' !== $pair['entity_type'] || '' !== $pair['entity_ref']
						)
					);
					$has_entity_pairs = [] !== $entity_pairs;
					$rows             = array_values(
						array_filter(
							$rows,
							static function ( array $row ) use ( $entity_pairs ): bool {
								foreach ( $entity_pairs as $pair ) {
									if (
										(string) ( $row['entity_type'] ?? '' ) === $pair['entity_type']
										&& (string) ( $row['entity_ref'] ?? '' ) === $pair['entity_ref']
									) {
										return true;
									}
								}

								return false;
							}
						)
					);
				}

				if ( ! $has_entity_pairs ) {
					if ( preg_match( "/entity_type\s*=\s*'([^']*)'/i", $query, $matches ) ) {
						$entity_type = stripslashes( (string) ( $matches[1] ?? '' ) );
						$rows        = array_values(
							array_filter(
								$rows,
								static fn ( array $row ): bool => (string) ( $row['entity_type'] ?? '' ) === $entity_type
							)
						);
					}

					if ( preg_match( "/surface\s*<>\s*'([^']*)'/i", $query, $matches ) ) {
						$surface = stripslashes( (string) ( $matches[1] ?? '' ) );
						$rows    = array_values(
							array_filter(
								$rows,
								static fn ( array $row ): bool => (string) ( $row['surface'] ?? '' ) !== $surface
							)
						);
					}

					foreach (
						[
							'admin_post_type',
							'admin_operation_type',
							'admin_provider',
							'admin_provider_path',
							'admin_configuration_owner',
							'admin_credential_source',
							'admin_selected_provider',
						] as $column
					) {
						if ( preg_match( "/(?:\\w+\\.)?{$column}\\s*=\\s*'([^']*)'/i", $query, $matches ) ) {
							$value = stripslashes( (string) ( $matches[1] ?? '' ) );
							$rows  = array_values(
								array_filter(
									$rows,
									static fn ( array $row ): bool => (string) ( $row[ $column ] ?? '' ) === $value
								)
							);
						}

						if ( preg_match( "/(?:\\w+\\.)?{$column}\\s*<>\\s*'([^']*)'/i", $query, $matches ) ) {
							$value = stripslashes( (string) ( $matches[1] ?? '' ) );
							$rows  = array_values(
								array_filter(
									$rows,
									static fn ( array $row ): bool => (string) ( $row[ $column ] ?? '' ) !== $value
								)
							);
						}
					}

					if ( preg_match( '/(?:\w+\.)?user_id\s*=\s*(\d+)/i', $query, $matches ) ) {
						$user_id = (int) ( $matches[1] ?? 0 );
						$rows    = array_values(
							array_filter(
								$rows,
								static fn ( array $row ): bool => (int) ( $row['user_id'] ?? 0 ) === $user_id
							)
						);
					}

					if ( preg_match( '/(?:\w+\.)?user_id\s*<>\s*(\d+)/i', $query, $matches ) ) {
						$user_id = (int) ( $matches[1] ?? 0 );
						$rows    = array_values(
							array_filter(
								$rows,
								static fn ( array $row ): bool => (int) ( $row['user_id'] ?? 0 ) !== $user_id
							)
						);
					}

					if ( preg_match( "/entity_ref\s*=\s*'([^']*)'/i", $query, $matches ) ) {
						$entity_ref = stripslashes( (string) ( $matches[1] ?? '' ) );
						$rows       = array_values(
							array_filter(
								$rows,
								static fn ( array $row ): bool => (string) ( $row['entity_ref'] ?? '' ) === $entity_ref
							)
						);
					}
				}

					if ( preg_match( "/activity_id\s*=\s*'([^']*)'/i", $query, $matches ) ) {
						$activity_id = stripslashes( (string) ( $matches[1] ?? '' ) );
						$rows        = array_values(
							array_filter(
							$rows,
							static fn ( array $row ): bool => (string) ( $row['activity_id'] ?? '' ) === $activity_id
							)
						);
					}

					if ( preg_match_all( "/(?:\\w+\\.)?created_at\\s*(>=|<=|<|>)\\s*'([^']+)'/i", $query, $matches, PREG_SET_ORDER ) ) {
						foreach ( $matches as $match ) {
							$operator = (string) ( $match[1] ?? '>=' );
							$value    = stripslashes( (string) ( $match[2] ?? '' ) );
							$rows     = array_values(
								array_filter(
									$rows,
									static function ( array $row ) use ( $operator, $value ): bool {
										$created_at = (string) ( $row['created_at'] ?? '' );

										return match ( $operator ) {
											'>'     => $created_at > $value,
											'<='    => $created_at <= $value,
											'<'     => $created_at < $value,
											default => $created_at >= $value,
										};
									}
								)
							);
						}
					}

					if ( preg_match( "/LOWER\\(t\\.admin_search_text\\)\\s+LIKE\\s+'([^']*)'/i", $query, $matches ) ) {
						$needle = strtolower( trim( stripslashes( (string) ( $matches[1] ?? '' ) ), '%' ) );
						$rows   = array_values(
							array_filter(
								$rows,
								static function ( array $row ) use ( $needle ): bool {
									if ( '' === $needle ) {
										return true;
									}

									foreach (
										[
											'admin_search_text',
											'surface',
											'admin_post_type',
											'admin_entity_id',
											'admin_provider',
											'admin_provider_path',
											'admin_configuration_owner',
											'admin_credential_source',
											'admin_selected_provider',
										] as $column
									) {
										if ( str_contains( strtolower( (string) ( $row[ $column ] ?? '' ) ), $needle ) ) {
											return true;
										}
									}

									return false;
								}
							)
						);
					}

				if ( preg_match( "/activity_id\s+IN\s*\\(([^\\)]+)\\)/i", $query, $matches ) ) {
					$activity_ids = array_values(
						array_filter(
							array_map(
								static fn ( string $value ): string => trim( stripslashes( $value ), " \t\n\r\0\x0B'" ),
								explode( ',', (string) ( $matches[1] ?? '' ) )
							)
						)
					);
					$rows         = array_values(
						array_filter(
							$rows,
							static fn ( array $row ): bool => in_array(
								(string) ( $row['activity_id'] ?? '' ),
								$activity_ids,
								true
							)
						)
					);
				}

				if ( preg_match( "/FIND_IN_SET\\s*\\(\\s*activity_id\\s*,\\s*'([^']*)'\\s*\\)\\s*>\\s*0/i", $query, $matches ) ) {
					$activity_ids = array_values(
						array_filter(
							array_map(
								static fn ( string $value ): string => trim( stripslashes( $value ) ),
								explode( ',', (string) ( $matches[1] ?? '' ) )
							)
						)
					);
					$rows         = array_values(
						array_filter(
							$rows,
							static fn ( array $row ): bool => in_array(
								(string) ( $row['activity_id'] ?? '' ),
								$activity_ids,
								true
							)
						)
					);
				}

					if ( preg_match( '/COUNT\(\*\)\s+AS\s+total/i', $query ) && str_contains( $query, 'AS admin_status' ) ) {
						$grouped = [];

						foreach ( $rows as $row ) {
							$status = $this->resolve_activity_admin_status( $row, $all_rows );

							if ( ! isset( $grouped[ $status ] ) ) {
								$grouped[ $status ] = 0;
							}

							++$grouped[ $status ];
						}

						ksort( $grouped );

						return array_map(
							static fn ( string $status, int $total ): array => [
								'admin_status' => $status,
								'total'        => $total,
							],
							array_keys( $grouped ),
							array_values( $grouped )
						);
					}

					if ( preg_match( '/SELECT\s+(.+?)\s+AS\s+value(?:,\s+(.+?)\s+AS\s+label)?\s+FROM\s+/is', $query, $matches ) ) {
						$value_column = $this->normalize_select_column( (string) ( $matches[1] ?? '' ) );
						$label_column = isset( $matches[2] ) ? $this->normalize_select_column( (string) $matches[2] ) : '';
						$grouped      = [];

						foreach ( $rows as $row ) {
							$value = (string) ( $row[ $value_column ] ?? '' );
							$label = '' !== $label_column ? (string) ( $row[ $label_column ] ?? '' ) : '';

							if ( '' === $value || isset( $grouped[ $value . "\0" . $label ] ) ) {
								continue;
							}

							$grouped[ $value . "\0" . $label ] = [
								'value' => $value,
								'label' => $label,
							];
						}

						usort(
							$grouped,
							static fn ( array $left, array $right ): int => strnatcasecmp(
								(string) ( $left['label'] ?: $left['value'] ),
								(string) ( $right['label'] ?: $right['value'] )
							)
						);

						return array_values( $grouped );
					}

					$order_column    = 'created_at';
					$order_direction = 'ASC';

					if ( preg_match( '/ORDER BY\s+(?:\w+\.)?([a-z_]+)\s+(ASC|DESC)/i', $query, $matches ) ) {
						$order_column    = strtolower( (string) ( $matches[1] ?? 'created_at' ) );
						$order_direction = strtoupper( (string) ( $matches[2] ?? 'ASC' ) );
					}

					usort(
						$rows,
						static function ( array $left, array $right ) use ( $order_column, $order_direction ): int {
							if ( in_array( $order_column, [ 'id', 'user_id' ], true ) ) {
								$result = (int) ( $left[ $order_column ] ?? 0 ) <=> (int) ( $right[ $order_column ] ?? 0 );
							} else {
								$left_value  = (string) ( $left[ $order_column ] ?? '' );
								$right_value = (string) ( $right[ $order_column ] ?? '' );
								$result      = strcmp( $left_value, $right_value );
							}

							if ( 0 === $result ) {
								$result = (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
							}

							return 'DESC' === $order_direction ? -1 * $result : $result;
						}
					);

					if ( preg_match( '/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $query, $matches ) ) {
						$rows = array_slice(
							$rows,
							(int) ( $matches[2] ?? 0 ),
							(int) ( $matches[1] ?? 0 )
						);
					}

				if ( preg_match( '/SELECT\s+(.+?)\s+FROM\s+/is', $query, $matches ) ) {
					$select_clause = trim( (string) ( $matches[1] ?? '*' ) );

					if ( '*' !== $select_clause ) {
						$columns = array_values(
							array_filter(
								array_map(
									static fn ( string $column ): string => trim(
										str_replace( '`', '', $column )
									),
									explode( ',', $select_clause )
								),
								static fn ( string $column ): bool => '' !== $column
							)
						);
						$rows    = array_map(
							static fn ( array $row ): array => array_intersect_key(
								$row,
								array_flip( $columns )
							),
							$rows
						);
					}
				}

				if ( ARRAY_A === $output ) {
					return $rows;
				}

				return array_map(
					static fn ( array $row ): object => (object) $row,
					$rows
				);
			}

				private function row_matches( array $row, array $where ): bool {
					foreach ( $where as $column => $value ) {
						if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
							return false;
					}
				}

					return true;
				}

				private function normalize_select_column( string $column ): string {
					$column = trim( $column );

					if ( str_contains( $column, '.' ) ) {
						$parts  = explode( '.', $column );
						$column = (string) end( $parts );
					}

					return trim( str_replace( '`', '', $column ) );
				}

				/**
				 * @param array<string, mixed> $row
				 * @param array<int, array<string, mixed>> $all_rows
				 */
				private function resolve_activity_admin_status( array $row, array $all_rows ): string {
					$undo = json_decode( (string) ( $row['undo_state'] ?? '' ), true );
					$undo_status = is_array( $undo ) ? (string) ( $undo['status'] ?? 'available' ) : 'available';
					$is_review = 'request_diagnostic' === (string) ( $row['activity_type'] ?? '' )
						|| 'review' === (string) ( $row['execution_result'] ?? '' );

					if ( $is_review ) {
						return 'failed' === $undo_status ? 'failed' : 'review';
					}

					if ( 'undone' === $undo_status ) {
						return 'undone';
					}

					foreach ( $all_rows as $candidate ) {
						if (
							(string) ( $candidate['entity_type'] ?? '' ) !== (string) ( $row['entity_type'] ?? '' )
							|| (string) ( $candidate['entity_ref'] ?? '' ) !== (string) ( $row['entity_ref'] ?? '' )
							|| (
								'' === (string) ( $row['entity_type'] ?? '' )
								&& '' === (string) ( $row['entity_ref'] ?? '' )
							)
						) {
							continue;
						}

						$is_newer = (string) ( $candidate['created_at'] ?? '' ) > (string) ( $row['created_at'] ?? '' )
							|| (
								(string) ( $candidate['created_at'] ?? '' ) === (string) ( $row['created_at'] ?? '' )
								&& (int) ( $candidate['id'] ?? 0 ) > (int) ( $row['id'] ?? 0 )
							);

						if ( ! $is_newer ) {
							continue;
						}

						$candidate_undo = json_decode( (string) ( $candidate['undo_state'] ?? '' ), true );
						$candidate_status = is_array( $candidate_undo ) ? (string) ( $candidate_undo['status'] ?? 'available' ) : 'available';
						$candidate_review = 'request_diagnostic' === (string) ( $candidate['activity_type'] ?? '' )
							|| 'review' === (string) ( $candidate['execution_result'] ?? '' );

						if ( ! $candidate_review && 'undone' !== $candidate_status ) {
							return 'blocked';
						}
					}

					return 'failed' === $undo_status ? 'failed' : 'applied';
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

			/**
			 * @return array<string, object>
			 */
			public function get_all_registered(): array {
				return $this->registered;
			}

			public function register( string $block_name, array $args ): void {
				$block_type = (object) $args;
				$block_type->name = $block_name;

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

	if ( ! class_exists( 'WP_Screen' ) ) {
		class WP_Screen {

			/** @var array<int, array<string, mixed>> */
			public array $help_tabs = [];

			public string $help_sidebar = '';

			public function add_help_tab( array $args ): void {
				$this->help_tabs[] = $args;
			}

			public function set_help_sidebar( string $content ): void {
				$this->help_sidebar = $content;
			}
		}
	}

	if ( ! class_exists( 'WP_Theme' ) ) {
		class WP_Theme {

			/**
			 * @param array<string, mixed> $data
			 */
			public function __construct(
				private array $data = []
			) {
			}

			public function get( string $field ) {
				return match ( $field ) {
					'Name'       => $this->data['name'] ?? '',
					'Version'    => $this->data['version'] ?? '',
					'Stylesheet' => $this->data['stylesheet'] ?? '',
					'Template'   => $this->data['template'] ?? '',
					default      => $this->data[ $field ] ?? '',
				};
			}

			public function get_stylesheet(): string {
				return (string) ( $this->data['stylesheet'] ?? '' );
			}

			public function get_template(): string {
				return (string) ( $this->data['template'] ?? '' );
			}
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) {
			return WordPressTestState::$options[ $name ] ?? $default;
		}
	}

	if ( ! function_exists( 'register_post_type' ) ) {
		function register_post_type( string $post_type, array $args = [] ): object {
			WordPressTestState::$registered_post_types[ $post_type ] = $args;

			return (object) array_merge( [ 'name' => $post_type ], $args );
		}
	}

	if ( ! function_exists( 'post_type_exists' ) ) {
		function post_type_exists( string $post_type ): bool {
			return array_key_exists( $post_type, WordPressTestState::$registered_post_types );
		}
	}

	if ( ! function_exists( 'register_taxonomy' ) ) {
		function register_taxonomy( string $taxonomy, $object_type, array $args = [] ): object {
			WordPressTestState::$registered_taxonomies[ $taxonomy ] = [
				'object_type' => $object_type,
				'args'        => $args,
			];

			return (object) array_merge( [ 'name' => $taxonomy ], $args );
		}
	}

	if ( ! function_exists( 'taxonomy_exists' ) ) {
		function taxonomy_exists( string $taxonomy ): bool {
			return array_key_exists( $taxonomy, WordPressTestState::$registered_taxonomies );
		}
	}

	if ( ! function_exists( 'wp_is_connector_registered' ) ) {
		function wp_is_connector_registered( string $id ): bool {
			$error_message = WordPressTestState::get_connector_api_error( __FUNCTION__ );
			if ( null !== $error_message ) {
				throw new \RuntimeException( esc_html( sanitize_text_field( $error_message ) ) );
			}

			return array_key_exists( $id, WordPressTestState::$connectors );
		}
	}

	if ( ! function_exists( 'wp_get_connector' ) ) {
		function wp_get_connector( string $id ): ?array {
			$error_message = WordPressTestState::get_connector_api_error( __FUNCTION__ );
			if ( null !== $error_message ) {
				throw new \RuntimeException( esc_html( sanitize_text_field( $error_message ) ) );
			}

			$connector = WordPressTestState::$connectors[ $id ] ?? null;

			return is_array( $connector ) ? $connector : null;
		}
	}

	if ( ! function_exists( 'wp_get_connectors' ) ) {
		function wp_get_connectors(): array {
			$error_message = WordPressTestState::get_connector_api_error( __FUNCTION__ );
			if ( null !== $error_message ) {
				throw new \RuntimeException( esc_html( sanitize_text_field( $error_message ) ) );
			}

			return WordPressTestState::$connectors;
		}
	}

	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, array $defaults = [] ): array {
			if ( is_object( $args ) ) {
				$args = get_object_vars( $args );
			}

			if ( ! is_array( $args ) ) {
				$args = [];
			}

			return array_merge( $defaults, $args );
		}
	}

	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( string $url, int $component = -1 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This test bootstrap provides the WordPress compatibility wrapper when core is unavailable.
			return parse_url( $url, $component );
		}
	}

	if ( ! function_exists( 'home_url' ) ) {
		function home_url( string $path = '', ?string $scheme = null ): string {
			$base = 'https://example.test';

			if ( $path === '' ) {
				return $base;
			}

			return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
		}
	}

	if ( ! function_exists( 'plugin_dir_path' ) ) {
		function plugin_dir_path( string $file ): string {
			return dirname( $file ) . '/';
		}
	}

	if ( ! function_exists( 'plugin_dir_url' ) ) {
		function plugin_dir_url( string $file ): string {
			unset( $file );

			return 'https://example.test/wp-content/plugins/flavor-agent/';
		}
	}

	if ( ! function_exists( 'register_activation_hook' ) ) {
		function register_activation_hook( string $file, $callback ): void {
			if ( is_callable( $callback ) ) {
				WordPressTestState::$activation_hooks[ $file ] = $callback;
			}
		}
	}

	if ( ! function_exists( 'register_deactivation_hook' ) ) {
		function register_deactivation_hook( string $file, $callback ): void {
			if ( is_callable( $callback ) ) {
				WordPressTestState::$deactivation_hooks[ $file ] = $callback;
			}
		}
	}

	if ( ! function_exists( 'untrailingslashit' ) ) {
		function untrailingslashit( string $value ): string {
			return rtrim( $value, '/' );
		}
	}

	if ( ! function_exists( 'get_current_blog_id' ) ) {
		function get_current_blog_id(): int {
			return 1;
		}
	}

	if ( ! function_exists( 'wp_get_environment_type' ) ) {
		function wp_get_environment_type(): string {
			return 'tests';
		}
	}

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin(): bool {
			return false;
		}
	}

	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron(): bool {
			return false;
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
			WordPressTestState::$transients[ $name ]            = $value;
			WordPressTestState::$transient_expirations[ $name ] = $expiration;

			return true;
		}
	}

	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( string $name ): bool {
			unset( WordPressTestState::$transients[ $name ] );
			unset( WordPressTestState::$transient_expirations[ $name ] );

			return true;
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $capability, ...$args ): bool {
			if ( [] !== $args ) {
				$specific_key = $capability . ':' . implode(
					':',
					array_map(
						static fn ( $arg ): string => is_scalar( $arg ) || null === $arg
							? (string) $arg
							: wp_json_encode( $arg ),
						$args
					)
				);

				if ( array_key_exists( $specific_key, WordPressTestState::$capabilities ) ) {
					return (bool) WordPressTestState::$capabilities[ $specific_key ];
				}
			}

			if ( is_callable( WordPressTestState::$capabilities[ $capability ] ?? null ) ) {
				return (bool) call_user_func(
					WordPressTestState::$capabilities[ $capability ],
					...$args
				);
			}

			return (bool) ( WordPressTestState::$capabilities[ $capability ] ?? false );
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int {
			return WordPressTestState::$current_user_id;
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}

	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}

	if ( ! function_exists( 'esc_textarea' ) ) {
		function esc_textarea( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}

	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( string $text, string $domain = 'default' ): string {
			unset( $domain );

			return esc_html( $text );
		}
	}

	if ( ! function_exists( 'esc_attr__' ) ) {
		function esc_attr__( string $text, string $domain = 'default' ): string {
			unset( $domain );

			return esc_attr( $text );
		}
	}

	if ( ! function_exists( 'selected' ) ) {
		function selected( $selected, $current = true, bool $display = true ): string {
			$result = '';

			if ( (string) $selected === (string) $current ) {
				$result = 'selected="selected"';
			}

			if ( $display && '' !== $result ) {
				echo 'selected="selected"';
			}

			return $result;
		}
	}

	if ( ! function_exists( '__return_true' ) ) {
		function __return_true(): bool {
			return true;
		}
	}

	if ( ! function_exists( '__return_false' ) ) {
		function __return_false(): bool {
			return false;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			if ( ! isset( WordPressTestState::$filters[ $hook_name ] ) ) {
				WordPressTestState::$filters[ $hook_name ] = [];
			}

			if ( ! isset( WordPressTestState::$filters[ $hook_name ][ $priority ] ) ) {
				WordPressTestState::$filters[ $hook_name ][ $priority ] = [];
			}

			WordPressTestState::$filters[ $hook_name ][ $priority ][] = [
				'callback'      => $callback,
				'accepted_args' => max( 0, $accepted_args ),
			];

			return true;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			return add_filter( $hook_name, $callback, $priority, $accepted_args );
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook_name, ...$args ): void {
			if ( empty( WordPressTestState::$filters[ $hook_name ] ) ) {
				return;
			}

			$callbacks = WordPressTestState::$filters[ $hook_name ];
			ksort( $callbacks );

			foreach ( $callbacks as $entries ) {
				foreach ( $entries as $entry ) {
					$accepted_args = (int) ( $entry['accepted_args'] ?? 1 );
					$callback_args = 0 === $accepted_args
						? []
						: array_slice( $args, 0, $accepted_args );
					call_user_func_array( $entry['callback'], $callback_args );
				}
			}
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value, ...$args ) {
			if ( empty( WordPressTestState::$filters[ $hook_name ] ) ) {
				return $value;
			}

			$callbacks = WordPressTestState::$filters[ $hook_name ];
			ksort( $callbacks );

			foreach ( $callbacks as $entries ) {
				foreach ( $entries as $entry ) {
					$accepted_args = (int) ( $entry['accepted_args'] ?? 1 );
					$callback_args = 0 === $accepted_args
						? []
						: array_slice( array_merge( [ $value ], $args ), 0, $accepted_args );
					$value         = call_user_func_array( $entry['callback'], $callback_args );
				}
			}

			return $value;
		}
	}

	if ( ! function_exists( 'remove_filter' ) ) {
		function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
			$entries = WordPressTestState::$filters[ $hook_name ][ $priority ] ?? null;

			if ( ! is_array( $entries ) ) {
				return false;
			}

			foreach ( $entries as $index => $entry ) {
				if ( ( $entry['callback'] ?? null ) !== $callback ) {
					continue;
				}

				unset( WordPressTestState::$filters[ $hook_name ][ $priority ][ $index ] );

				if ( [] === WordPressTestState::$filters[ $hook_name ][ $priority ] ) {
					unset( WordPressTestState::$filters[ $hook_name ][ $priority ] );
				}

				if ( [] === WordPressTestState::$filters[ $hook_name ] ) {
					unset( WordPressTestState::$filters[ $hook_name ] );
				}

				return true;
			}

			return false;
		}
	}

	if ( ! function_exists( 'remove_all_filters' ) ) {
		function remove_all_filters( ?string $hook_name = null, $priority = false ): bool {
			if ( null === $hook_name ) {
				WordPressTestState::$filters = [];
				return true;
			}

			if ( false === $priority ) {
				unset( WordPressTestState::$filters[ $hook_name ] );
				return true;
			}

			unset( WordPressTestState::$filters[ $hook_name ][ (int) $priority ] );

			if ( [] === ( WordPressTestState::$filters[ $hook_name ] ?? [] ) ) {
				unset( WordPressTestState::$filters[ $hook_name ] );
			}

			return true;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return $value instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( string $key ): string {
			$key = strtolower( $key );

			return preg_replace( '/[^a-z0-9_-]/', '', $key ) ?? '';
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( string $title ): string {
			$title = strtolower( sanitize_text_field( $title ) );
			$title = preg_replace( '/[^a-z0-9_\s-]/', '', $title ) ?? '';
			$title = preg_replace( '/[\s-]+/', '-', $title ) ?? '';

			return trim( $title, '-' );
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

	if ( ! function_exists( 'absint' ) ) {
		function absint( $maybeint ): int {
			return abs( (int) $maybeint );
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

	if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
		function rest_sanitize_boolean( $value ): bool {
			return in_array( $value, [ true, 1, '1', 'true', 'yes', 'on' ], true );
		}
	}

	if ( ! function_exists( 'sanitize_html_class' ) ) {
		function sanitize_html_class( string $class, string $fallback = '' ): string {
			$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $class ) ?? '';

			if ( '' === $sanitized ) {
				return $fallback;
			}

			return $sanitized;
		}
	}

	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( string $content ): string {
			return $content;
		}
	}

	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( string $path = '' ): string {
			$normalized = ltrim( $path, '/' );

			return 'https://example.test/wp-admin/' . $normalized;
		}
	}

	if ( ! function_exists( 'rest_url' ) ) {
		function rest_url( string $path = '' ): string {
			$normalized = ltrim( $path, '/' );

			return 'https://example.test/wp-json/' . $normalized;
		}
	}

	if ( ! function_exists( 'wp_create_nonce' ) ) {
		function wp_create_nonce( string $action = '-1' ): string {
			return 'nonce-' . $action;
		}
	}

	if ( ! function_exists( 'wp_get_theme' ) ) {
		function wp_get_theme( ?string $stylesheet = null, ?string $theme_root = null ) {
			unset( $stylesheet, $theme_root );

			return new \WP_Theme( WordPressTestState::$active_theme );
		}
	}

	if ( ! function_exists( 'get_current_screen' ) ) {
		function get_current_screen() {
			return WordPressTestState::$current_screen;
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

	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( array $args = [] ): array {
			WordPressTestState::$get_posts_calls[] = $args;

			$posts     = array_values( WordPressTestState::$posts );
			$post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : '';
			$post_status = isset( $args['post_status'] ) ? $args['post_status'] : 'publish';
			$search      = isset( $args['s'] ) ? sanitize_text_field( (string) $args['s'] ) : '';
			$orderby     = isset( $args['orderby'] ) ? sanitize_key( (string) $args['orderby'] ) : 'date';
			$order       = strtoupper( (string) ( $args['order'] ?? 'DESC' ) );

			if ( '' !== $post_type ) {
				$posts = array_values(
					array_filter(
						$posts,
						static fn ( object $post ): bool => (string) ( $post->post_type ?? '' ) === $post_type
					)
				);
			}

		if ( 'any' !== $post_status ) {
			$allowed_statuses = is_array( $post_status ) ? $post_status : [ $post_status ];
			$posts            = array_values(
				array_filter(
						$posts,
						static fn ( object $post ): bool => in_array(
							(string) ( $post->post_status ?? '' ),
							array_map( 'strval', $allowed_statuses ),
							true
						)
				)
			);
		}

		if ( isset( $args['author'] ) ) {
			$author_id = (int) $args['author'];
			$posts     = array_values(
				array_filter(
					$posts,
					static fn ( object $post ): bool => (int) ( $post->post_author ?? 0 ) === $author_id
				)
			);
		}

		if ( ! empty( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ) {
			$excluded = array_map( 'intval', $args['post__not_in'] );
			$posts    = array_values(
				array_filter(
					$posts,
					static fn ( object $post ): bool => ! in_array( (int) ( $post->ID ?? 0 ), $excluded, true )
				)
			);
		}

		if ( isset( $args['has_password'] ) && false === $args['has_password'] ) {
			$posts = array_values(
				array_filter(
					$posts,
					static fn ( object $post ): bool => '' === (string) ( $post->post_password ?? '' )
				)
			);
		}

		if ( '' !== $search ) {
			$search = strtolower( $search );
				$posts  = array_values(
					array_filter(
						$posts,
						static function ( object $post ) use ( $search ): bool {
							$haystacks = [
								strtolower( (string) ( $post->post_title ?? '' ) ),
								strtolower( (string) ( $post->post_name ?? '' ) ),
							];

							foreach ( $haystacks as $haystack ) {
								if ( str_contains( $haystack, $search ) ) {
									return true;
								}
							}

							return false;
						}
					)
				);
			}

			usort(
				$posts,
				static function ( object $left, object $right ) use ( $orderby, $order ): int {
					$comparison = match ( $orderby ) {
						'title' => strcasecmp(
							(string) ( $left->post_title ?? '' ),
							(string) ( $right->post_title ?? '' )
						),
						'id' => (int) ( $left->ID ?? 0 ) <=> (int) ( $right->ID ?? 0 ),
						default => strcmp(
							(string) ( $left->post_date_gmt ?? '' ),
							(string) ( $right->post_date_gmt ?? '' )
						),
					};

					return 'ASC' === $order ? $comparison : -1 * $comparison;
				}
			);

			$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
			$limit  = isset( $args['posts_per_page'] ) && (int) $args['posts_per_page'] >= 0
				? (int) $args['posts_per_page']
				: null;

			if ( $offset > 0 || null !== $limit ) {
				$posts = array_slice( $posts, $offset, $limit );
			}

			return $posts;
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
			$meta = WordPressTestState::$post_meta[ $post_id ] ?? [];

			if ( '' === $key ) {
				return $meta;
			}

			if ( ! array_key_exists( $key, $meta ) ) {
				return $single ? '' : [];
			}

			$value = $meta[ $key ];

			if ( $single ) {
				return $value;
			}

			return is_array( $value ) ? $value : [ $value ];
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
			return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $depth );
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

	if ( ! function_exists( 'register_rest_route' ) ) {
		function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): bool {
			unset( $override );

			$route_path   = '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' );
			$is_list      = [] === $args
				|| array_keys( $args ) === range( 0, count( $args ) - 1 );
			$route_config = [
				'namespace' => trim( $namespace, '/' ),
				'route'     => '/' . ltrim( $route, '/' ),
				'path'      => $route_path,
				'endpoints' => $is_list ? $args : [ $args ],
				'raw'       => $args,
			];

			WordPressTestState::$rest_routes[ $route_path ] = $route_config;

			return true;
		}
	}

	if ( ! function_exists( 'register_block_pattern_category' ) ) {
		function register_block_pattern_category( string $name, array $properties ): bool {
			WordPressTestState::$registered_block_pattern_categories[ $name ] = $properties;

			return true;
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

	if ( ! function_exists( 'wp_remote_request' ) ) {
		function wp_remote_request( string $url, array $args = [] ) {
			$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );

			if ( 'GET' === $method ) {
				return wp_remote_get( $url, $args );
			}

			return wp_remote_post( $url, $args );
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

	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		function wp_remote_retrieve_header( $response, string $header ) {
			if ( ! is_array( $response ) || ! is_array( $response['headers'] ?? null ) ) {
				return false;
			}

			$normalized_header = strtolower( $header );

			foreach ( $response['headers'] as $key => $value ) {
				if ( strtolower( (string) $key ) === $normalized_header ) {
					return $value;
				}
			}

			return false;
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text ): string {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This test bootstrap defines the WordPress wrapper when core is unavailable.
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
					esc_attr( $type ),
					esc_html( (string) ( $details['message'] ?? '' ) )
				);
			}
		}
	}

	if ( ! function_exists( 'settings_fields' ) ) {
		function settings_fields( string $option_group ): void {
			printf(
				'<input type="hidden" name="option_page" value="%s" /><input type="hidden" name="action" value="update" />',
				esc_attr( $option_group )
			);
		}
	}

	if ( ! function_exists( 'register_setting' ) ) {
		function register_setting( string $option_group, string $option_name, array $args = [] ): void {
			$GLOBALS['wp_registered_settings'][ $option_name ] = array_merge(
				$args,
				[
					'option_group' => $option_group,
					'option_name'  => $option_name,
				]
			);
		}
	}

	if ( ! function_exists( 'add_settings_section' ) ) {
		function add_settings_section(
			string $id,
			string $title,
			callable $callback,
			string $page
		): void {
			$GLOBALS['wp_settings_sections'][ $page ][ $id ] = [
				'id'       => $id,
				'title'    => $title,
				'callback' => $callback,
			];
		}
	}

	if ( ! function_exists( 'add_settings_field' ) ) {
		function add_settings_field(
			string $id,
			string $title,
			callable $callback,
			string $page,
			string $section = 'default',
			array $args = []
		): void {
			$GLOBALS['wp_settings_fields'][ $page ][ $section ][ $id ] = [
				'id'       => $id,
				'title'    => $title,
				'callback' => $callback,
				'args'     => $args,
			];
		}
	}

	if ( ! function_exists( 'do_settings_sections' ) ) {
		function do_settings_sections( string $page ): void {
			unset( $page );
		}
	}

	if ( ! function_exists( 'submit_button' ) ) {
		function submit_button( string $text = 'Save Changes' ): void {
			printf(
				'<button type="submit">%s</button>',
				esc_html( $text )
			);
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

	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {

			public int $ID = 0;

			public string $post_title = '';

			public string $post_content = '';

			public string $post_excerpt = '';

			public string $post_status = 'publish';

			public string $post_type = 'post';

		public int $post_author = 0;

		public string $post_password = '';

		public string $post_date = '';

		public string $post_date_gmt = '';

		/**
		 * @param array<string, mixed> $fields
			 */
			public function __construct( array $fields = [] ) {
				foreach ( $fields as $key => $value ) {
					if ( property_exists( $this, $key ) ) {
						$this->{$key} = $value;
					}
				}
			}
		}
	}

	if ( ! function_exists( 'mysql2date' ) ) {
		function mysql2date( string $format, string $date, bool $translate = true ): string {
			unset( $translate );

			if ( '' === $date ) {
				return '';
			}

			$timestamp = strtotime( $date );
			if ( false === $timestamp ) {
				return '';
			}

			return date( $format, $timestamp );
		}
	}

	if ( ! function_exists( 'setup_postdata' ) ) {
		function setup_postdata( $post ): bool {
			if ( $post instanceof WP_Post ) {
				WordPressTestState::$current_post = $post;

				return true;
			}

			return false;
		}
	}

	if ( ! function_exists( 'wp_reset_postdata' ) ) {
		function wp_reset_postdata(): void {
			WordPressTestState::$current_post = null;
		}
	}

	if ( ! function_exists( 'register_block_type' ) ) {
		function register_block_type( string $name, array $args = [] ): object {
			\WP_Block_Type_Registry::get_instance()->register( $name, $args );
			$registered = \WP_Block_Type_Registry::get_instance()->get_registered( $name );

			return is_object( $registered )
				? $registered
				: (object) array_merge( $args, [ 'name' => $name ] );
		}
	}

	if ( ! function_exists( 'render_block' ) ) {
		function render_block( array $block ): string {
			$name = $block['blockName'] ?? null;

			if ( null === $name ) {
				return (string) ( $block['innerHTML'] ?? '' );
			}

			$rendered_inner  = '';
			$inner_blocks    = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
			$inner_block_idx = 0;
			$inner_content   = $block['innerContent'] ?? [ $block['innerHTML'] ?? '' ];

			if ( ! is_array( $inner_content ) ) {
				$inner_content = [ (string) $inner_content ];
			}

			foreach ( $inner_content as $chunk ) {
				if ( is_string( $chunk ) ) {
					$rendered_inner .= $chunk;
					continue;
				}

				$next = $inner_blocks[ $inner_block_idx++ ] ?? null;
				if ( is_array( $next ) ) {
					$rendered_inner .= render_block( $next );
				}
			}

			$registered      = \WP_Block_Type_Registry::get_instance()->get_registered( (string) $name );
			$render_callback = is_object( $registered ) ? ( $registered->render_callback ?? null ) : null;

			if ( is_callable( $render_callback ) ) {
				return (string) call_user_func(
					$render_callback,
					$block['attrs'] ?? [],
					$rendered_inner,
					$block
				);
			}

			return $rendered_inner;
		}
	}

	if ( ! function_exists( 'parse_blocks' ) ) {
		function parse_blocks( string $content ): array {
			if ( '' === $content ) {
				return [];
			}

			$blocks = [];
			$offset = 0;
			$length = strlen( $content );

			while ( $offset < $length ) {
				$next = _flavor_agent_parse_next_block( $content, $offset );

				if ( null === $next ) {
					$remainder = substr( $content, $offset );
					if ( '' !== $remainder ) {
						$blocks[] = _flavor_agent_make_freeform_block( $remainder );
					}
					break;
				}

				if ( $next['start'] > $offset ) {
					$freeform = substr( $content, $offset, $next['start'] - $offset );
					if ( '' !== $freeform ) {
						$blocks[] = _flavor_agent_make_freeform_block( $freeform );
					}
				}

				$blocks[] = $next['parsed'];
				$offset   = $next['end'];
			}

			return $blocks;
		}
	}

	if ( ! function_exists( '_flavor_agent_make_freeform_block' ) ) {
		function _flavor_agent_make_freeform_block( string $html ): array {
			return [
				'blockName'    => null,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			];
		}
	}

	if ( ! function_exists( '_flavor_agent_parse_next_block' ) ) {
		function _flavor_agent_parse_next_block( string $content, int $offset ): ?array {
			$pattern = '/<!--\s+wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)\s*(\{.*?\})?\s*(\/)?-->/s';

			if ( ! preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				return null;
			}

			$full_match   = $match[0][0];
			$match_pos    = $match[0][1];
			$short_name   = $match[1][0];
			$block_name   = str_contains( $short_name, '/' ) ? $short_name : 'core/' . $short_name;
			$attrs_json   = $match[2][0] ?? '';
			$self_closing = ! empty( $match[3][0] );

			$attrs = [];
			if ( '' !== $attrs_json ) {
				$decoded = json_decode( $attrs_json, true );
				if ( is_array( $decoded ) ) {
					$attrs = $decoded;
				}
			}

			$opening_end = $match_pos + strlen( $full_match );

			if ( $self_closing ) {
				return [
					'start'  => $match_pos,
					'end'    => $opening_end,
					'parsed' => [
						'blockName'    => $block_name,
						'attrs'        => $attrs,
						'innerBlocks'  => [],
						'innerHTML'    => '',
						'innerContent' => [],
					],
				];
			}

			$close_tag      = '<!-- /wp:' . $short_name . ' -->';
			$same_open_regex = '/<!--\s+wp:' . preg_quote( $short_name, '/' ) . '(?:\s+\{.*?\})?\s*(\/)?-->/s';
			$depth           = 1;
			$scan_pos        = $opening_end;
			$close_pos       = -1;

			while ( $scan_pos < strlen( $content ) ) {
				$next_open  = preg_match( $same_open_regex, $content, $same_open_match, PREG_OFFSET_CAPTURE, $scan_pos )
					? $same_open_match[0][1]
					: false;
				$next_close = strpos( $content, $close_tag, $scan_pos );

				if ( false === $next_close ) {
					break;
				}

				if ( false !== $next_open && $next_open < $next_close ) {
					if ( empty( $same_open_match[1][0] ) ) {
						++$depth;
					}
					$scan_pos = $next_open + strlen( (string) $same_open_match[0][0] );
					continue;
				}

				--$depth;
				if ( 0 === $depth ) {
					$close_pos = $next_close;
					break;
				}

				$scan_pos = $next_close + strlen( $close_tag );
			}

			if ( $close_pos < 0 ) {
				return [
					'start'  => $match_pos,
					'end'    => $opening_end,
					'parsed' => [
						'blockName'    => $block_name,
						'attrs'        => $attrs,
						'innerBlocks'  => [],
						'innerHTML'    => '',
						'innerContent' => [],
					],
				];
			}

			$inner_offset  = $opening_end;
			$inner_end     = $close_pos;
			$inner_content = [];
			$inner_html    = '';
			$inner_blocks  = [];

			while ( $inner_offset < $inner_end ) {
				$child = _flavor_agent_parse_next_block( $content, $inner_offset );

				if ( null === $child || $child['start'] >= $inner_end ) {
					$tail = substr( $content, $inner_offset, $inner_end - $inner_offset );
					if ( '' !== $tail ) {
						$inner_content[] = $tail;
						$inner_html     .= $tail;
					}
					break;
				}

				if ( $child['start'] > $inner_offset ) {
					$prefix = substr( $content, $inner_offset, $child['start'] - $inner_offset );
					if ( '' !== $prefix ) {
						$inner_content[] = $prefix;
						$inner_html     .= $prefix;
					}
				}

				$inner_content[] = null;
				$inner_blocks[]  = $child['parsed'];
				$inner_offset    = $child['end'];
			}

			return [
				'start'  => $match_pos,
				'end'    => $close_pos + strlen( $close_tag ),
				'parsed' => [
					'blockName'    => $block_name,
					'attrs'        => $attrs,
					'innerBlocks'  => $inner_blocks,
					'innerHTML'    => $inner_html,
					'innerContent' => $inner_content,
				],
			];
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

	if ( ! function_exists( 'delete_option' ) ) {
		function delete_option( string $name ): bool {
			unset(
				WordPressTestState::$options[ $name ],
				WordPressTestState::$updated_options[ $name ]
			);

			return true;
		}
	}

	if ( ! function_exists( 'wp_schedule_event' ) ) {
		function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool {
			WordPressTestState::$scheduled_events[ $hook ] = [
				'hook'       => $hook,
				'timestamp'  => $timestamp,
				'recurrence' => $recurrence,
				'args'       => $args,
			];

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

	if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof wpdb ) {
		$GLOBALS['wpdb'] = new wpdb();
	}

	WordPressTestState::reset();
}
