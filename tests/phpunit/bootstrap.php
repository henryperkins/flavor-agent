<?php

declare(strict_types=1);

namespace {

	if (! defined('ABSPATH')) {
		if (! defined('FLAVOR_AGENT_TESTS_RUNNING')) {
			exit;
		}

		define('ABSPATH', __DIR__ . '/');
	}
}

namespace FlavorAgent\Tests\Support {

	final class WordPressTestState
	{

		public static array $global_settings = [];

		public static array $global_styles = [];

		public static array $active_theme = [];

		public static array $last_remote_post = [];

		public static array $last_remote_get = [];

		public static array $remote_post_calls = [];

		public static array $remote_get_calls = [];

		public static array $remote_post_responses = [];

		/** @var array<string, mixed> URL-substring → response, served before the queued/singular stubs. */
		public static array $remote_post_url_responses = [];

		public static array $remote_get_responses = [];

		public static array $last_ai_client_prompt = [];

		/** @var array<int, array{prompt: string, options: array<string, mixed>}> */
		public static array $ai_service_calls = [];

		public static ?\Throwable $ai_service_call_throws = null;

		/** @var array<int, mixed> */
		public static array $preferred_text_models = [];

		public static string $wpai_formatted_guidelines = '';

		/** @var array<int, array{categories: array<int, string>, blockName: string|null}> */
		public static array $wpai_guideline_calls = [];

		public static array $last_http_request_args = [];

		public static array $options = [];

		public static string $home_url = 'https://example.test';

		/** @var array<string, array<string, mixed>> */
		public static array $connectors = [];

		/** @var array<string, string> */
		public static array $connector_api_errors = [];

		public static array $capabilities = [];

		/** @var array<int, array{capability: string, args: array<int, mixed>}> */
		public static array $capability_checks = [];

		public static array $block_templates = [];

		/**
		 * Optional one-shot hook fired once, right after the first get_block_templates()
		 * read. Lets a test model a concurrent live-part change landing between an
		 * executor's initial read and its pre-persist write, to exercise the
		 * read -> write concurrency gate. Cleared after it fires and on reset().
		 *
		 * @var callable|null
		 */
		public static $block_templates_read_hook = null;

		/**
		 * Optional query-aware hook fired before get_block_templates() snapshots its
		 * store. Tests use it to publish a concurrent customization exactly when the
		 * executor enters its duplicate-row probe.
		 *
		 * @var callable|null
		 */
		public static $before_block_templates_query = null;

		public static array $transients = [];

		public static array $transient_expirations = [];

		public static array $registered_abilities = [];

		public static array $raw_registered_abilities = [];

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

		/** @var array<string, mixed> */
		public static array $option_autoload = [];

		/** @var array<string, true> Models Core's cached set of missing current-site options. */
		public static array $option_notoptions_cache = [];

		/** Models a database failure while inserting a strict option lock row. */
		public static bool $option_insert_fails = false;

		/** Models a database failure while deleting a strict option lock row. */
		public static bool $option_delete_fails = false;

		/** One-shot interleaving immediately before an option-lock INSERT. */
		public static $before_materialization_lock_insert = null;

		/** One-shot interleaving immediately after an owned option-lock DELETE. */
		public static $after_materialization_lock_delete = null;

		/** One-shot interleaving immediately before a post-content compensation CAS. */
		public static $before_post_content_compensation = null;

		/** One-shot interleaving immediately before a conditional post-content write. */
		public static $before_conditional_post_content_write = null;

		/** One-shot interleaving after wp_update_post() writes and before its caller resumes. */
		public static $after_wp_update_post = null;

		/** Models an exception-throwing persistent object-cache drop-in. */
		public static bool $wp_cache_delete_throws = false;

		/** @var array<string> */
		public static array $cleared_cron_hooks = [];

		/** @var array<int, object> */
		public static array $posts = [];

		/** @var array<int, int> */
		public static array $get_post_calls = [];

		/** @var array<int, int> */
		public static array $get_post_type_calls = [];

		/** @var array<int, array<string, mixed>> */
		public static array $updated_posts = [];

		/** @var array<int, array<string, mixed>> */
		public static array $inserted_posts = [];

		/** @var array<int, array<string, array<int, string>>> */
		public static array $object_terms = [];

		/**
		 * Models wp_insert_post() silently skipping or failing tax_input assignment.
		 * Core capability-gates the assignment and ignores wp_set_post_terms errors.
		 */
		public static bool $skip_insert_taxonomy_assignment = false;

		/** One-shot exact block-template lookup failure after a successful write. */
		public static bool $next_get_block_template_returns_null = false;

		/** Models core's semantic Block Hooks transformation of template content. */
		public static $block_template_content_transform = null;

		/** Models core's ignored-hook metadata preparation before template writes. */
		public static $block_template_write_preparer = null;

		/** @var array<int, int> */
		public static array $deleted_posts = [];

		/**
		 * One-shot: the next get_post() returns null. Models an object-cache miss
		 * or a filtered-away read, which is the only way to reach the
		 * post-insert read-back failure arm in the apply executors.
		 */
		public static bool $next_get_post_returns_null = false;

		/** One-shot unexpected failure from the next get_post() call. */
		public static ?\Throwable $next_get_post_throws = null;

		/** One-shot interleaving immediately before get_post() reads its row. */
		public static $before_get_post = null;

		/** One-shot: the next bound raw posts-table row read returns no row. */
		public static bool $next_raw_post_row_returns_null = false;

		/** One-shot interleaving immediately before a bound raw posts-table row read. */
		public static $before_raw_post_row = null;

		/** One-shot interleaving after the raw posts INSERT and before Core side effects. */
		public static $after_database_post_insert = null;

		/** One-shot interleaving immediately before an activity-table update. */
		public static $before_activity_table_update = null;

		/**
		 * When true, wp_delete_post() returns the WP_Post without removing it.
		 * Models a pre_delete_post filter that short-circuits deletion while
		 * still returning a post -- the exact case the executors guard against.
		 */
		public static bool $delete_post_short_circuits = false;

		/** @var array<int, int> */
		public static array $cleaned_post_caches = [];

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

		/** @var array<string, int> */
		public static array $do_action_counts = [];

		/** @var array<int, string> Stack of currently-firing action names. */
		public static array $current_actions = [];

		/** @var array<string, mixed> */
		public static array $wp_cli_commands = [];

		/** @var array<int, array{type: string, message: string}> */
		public static array $wp_cli_messages = [];
		public static ?int $wp_cli_exit_code = null;

		public static int $db_insert_id = 0;

		public static int $current_user_id = 0;

		public static int $current_blog_id = 1;

		/** @var array<int, array<string, mixed>> Seeded user records for get_userdata(): id → {display_name, user_login, roles}. */
		public static array $users = [];

		public static ?object $current_screen = null;

		public static mixed $remote_post_response = [];

		public static mixed $remote_get_response = [];

		public static bool $ai_client_supported = false;

		/** @var array<string, bool> */
		public static array $ai_client_provider_support = [];

		/** @var array<string, bool> */
		public static array $ai_client_feature_support = [];

		public static mixed $ai_client_generate_text_result = '';

		public static ?\Throwable $ai_client_generate_text_throws = null;

		public static mixed $ai_client_model_resolution_error = null;

		public static ?object $current_post = null;

		/**
		 * @param array<string, string> $errors
		 */
		public static function set_connector_api_errors(array $errors): void
		{
			self::$connector_api_errors = $errors;
		}

		public static function get_connector_api_error(string $function_name): ?string
		{
			return self::$connector_api_errors[$function_name] ?? null;
		}

		/**
		 * @param array<string, mixed> $prompt_state
		 */
		public static function ai_client_prompt_supports_text_generation(array $prompt_state): bool
		{
			if (
				array_key_exists('reasoning', self::$ai_client_feature_support)
				&& ! self::$ai_client_feature_support['reasoning']
				&& isset($prompt_state['reasoning'])
				&& '' !== (string) $prompt_state['reasoning']
			) {
				return false;
			}

			if (
				array_key_exists('json_schema', self::$ai_client_feature_support)
				&& ! self::$ai_client_feature_support['json_schema']
				&& is_array($prompt_state['json_schema'] ?? null)
			) {
				return false;
			}

			$provider = $prompt_state['provider'] ?? '';

			if (
				is_string($provider)
				&& '' !== $provider
				&& array_key_exists($provider, self::$ai_client_provider_support)
			) {
				return (bool) self::$ai_client_provider_support[$provider];
			}

			if (self::$ai_client_supported) {
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
		public static function pending_chat_output_text(): ?string
		{
			if (isset(self::$remote_post_response['body']) && is_string(self::$remote_post_response['body'])) {
				$decoded = json_decode(self::$remote_post_response['body'], true);

				if (self::is_chat_output_text_payload($decoded)) {
					return (string) $decoded['output_text'];
				}
			}

			foreach (self::$remote_post_responses as $queued) {
				if (is_array($queued) && isset($queued['body']) && is_string($queued['body'])) {
					$decoded = json_decode($queued['body'], true);

					if (self::is_chat_output_text_payload($decoded)) {
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
		public static function consume_pending_chat_response_for_ai_client(object $prompt_state): ?string
		{
			if (isset(self::$remote_post_response['body']) && is_string(self::$remote_post_response['body'])) {
				$decoded = json_decode(self::$remote_post_response['body'], true);

				if (self::is_chat_output_text_payload($decoded)) {
					self::record_synthetic_chat_remote_post_call($prompt_state);

					return (string) $decoded['output_text'];
				}
			}

			foreach (self::$remote_post_responses as $index => $queued) {
				if (! is_array($queued) || ! isset($queued['body']) || ! is_string($queued['body'])) {
					continue;
				}

				$decoded = json_decode($queued['body'], true);

				if (! self::is_chat_output_text_payload($decoded)) {
					continue;
				}

				array_splice(self::$remote_post_responses, $index, 1);
				self::record_synthetic_chat_remote_post_call($prompt_state);

				return (string) $decoded['output_text'];
			}

			return null;
		}

		private static function is_chat_output_text_payload(mixed $decoded): bool
		{
			return is_array($decoded)
				&& isset($decoded['output_text'])
				&& is_string($decoded['output_text']);
		}

		private static function record_synthetic_chat_remote_post_call(object $prompt_state): void
		{
			$prompt = (array) $prompt_state;

			$body = wp_json_encode(
				array_filter(
					[
						'model'        => 'provider-managed',
						'instructions' => isset($prompt['system']) ? (string) $prompt['system'] : '',
						'input'        => isset($prompt['text']) ? (string) $prompt['text'] : '',
						'reasoning'    => isset($prompt['reasoning']) && '' !== $prompt['reasoning']
							? ['effort' => (string) $prompt['reasoning']]
							: null,
						'text'         => isset($prompt['json_schema']) && is_array($prompt['json_schema'])
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
					'body'    => is_string($body) ? $body : '',
					'headers' => [],
				],
			];
			self::$remote_post_calls[] = self::$last_remote_post;
		}

		public static function reset(): void
		{
			self::$global_settings             = [];
			self::$global_styles               = [];
			self::$active_theme                = [];
			self::$last_remote_post            = [];
			self::$last_remote_get             = [];
			self::$remote_post_calls           = [];
			self::$remote_get_calls            = [];
			self::$remote_post_responses       = [];
			self::$remote_post_url_responses   = [];
			self::$remote_get_responses        = [];
			self::$last_ai_client_prompt       = [];
			self::$ai_service_calls            = [];
			self::$ai_service_call_throws      = null;
			self::$preferred_text_models       = [];
			self::$wpai_formatted_guidelines   = '';
			self::$wpai_guideline_calls        = [];
			self::$last_http_request_args      = [];
			self::$options                     = [];
			self::$home_url                    = 'https://example.test';
			self::$connectors                  = [];
			self::$connector_api_errors        = [];
			self::$capabilities                = [];
			self::$capability_checks           = [];
			self::$block_templates             = [];
			self::$block_templates_read_hook   = null;
			self::$before_block_templates_query = null;
			self::$transients                  = [];
			self::$transient_expirations       = [];
			self::$registered_abilities        = [];
			self::$raw_registered_abilities    = [];
			self::$registered_ability_categories = [];
			self::$settings_errors             = [];
			self::$rest_routes                 = [];
			self::$activation_hooks            = [];
			self::$deactivation_hooks          = [];
			self::$registered_block_pattern_categories = [];
			self::$scheduled_events            = [];
			self::$updated_options              = [];
			self::$option_autoload              = [];
			self::$option_notoptions_cache      = [];
			self::$option_insert_fails          = false;
			self::$option_delete_fails          = false;
			self::$before_materialization_lock_insert = null;
			self::$after_materialization_lock_delete  = null;
			self::$before_post_content_compensation   = null;
			self::$before_conditional_post_content_write = null;
			self::$after_wp_update_post               = null;
			self::$wp_cache_delete_throws       = false;
			self::$cleared_cron_hooks           = [];
			self::$posts                       = [];
			self::$get_post_calls              = [];
			self::$get_post_type_calls         = [];
			self::$updated_posts               = [];
			self::$inserted_posts              = [];
			self::$object_terms                        = [];
			self::$skip_insert_taxonomy_assignment     = false;
			self::$next_get_block_template_returns_null = false;
			self::$block_template_content_transform     = null;
			self::$block_template_write_preparer        = null;
			self::$deleted_posts               = [];
			self::$next_get_post_returns_null  = false;
			self::$next_get_post_throws        = null;
			self::$before_get_post             = null;
			self::$next_raw_post_row_returns_null = false;
			self::$before_raw_post_row             = null;
			self::$after_database_post_insert      = null;
			self::$before_activity_table_update    = null;
			self::$delete_post_short_circuits  = false;
			self::$cleaned_post_caches         = [];
			self::$registered_post_types       = [];
			self::$registered_taxonomies       = [];
			self::$get_posts_calls             = [];
			self::$post_meta                   = [];
			self::$db_tables                   = [];
			self::$db_queries                  = [];
			self::$filters                     = [];
			self::$do_action_counts            = [];
			self::$current_actions             = [];
			self::$wp_cli_commands             = [];
			self::$wp_cli_messages             = [];
			self::$wp_cli_exit_code            = null;
			self::$db_insert_id                = 0;
			self::$current_user_id             = 0;
			self::$current_blog_id             = 1;
			self::$users                       = [];
			self::$current_screen              = null;
			self::$remote_post_response        = [];
			self::$remote_get_response         = [];
			self::$ai_client_supported         = false;
			self::$ai_client_provider_support  = [];
			self::$ai_client_feature_support   = [];
			self::$ai_client_generate_text_result = '';
			self::$ai_client_generate_text_throws = null;
			self::$ai_client_model_resolution_error = null;
			self::$current_post                = null;

			$GLOBALS['wp_settings_fields']   = [];
			$GLOBALS['wp_settings_sections'] = [];
			$GLOBALS['wp_registered_settings'] = [];

			\WP_Block_Type_Registry::get_instance()->reset();
			\WP_Block_Patterns_Registry::get_instance()->reset();
			\WP_Block_Styles_Registry::get_instance()->reset();
		}
	}
}

namespace WordPress\AI_Client\Builders\Exception {

	if (! class_exists('WordPress\\AI_Client\\Builders\\Exception\\Prompt_Prevented_Exception')) {
		final class Prompt_Prevented_Exception extends \RuntimeException {}
	}
}

namespace WordPress\AiClient\Providers\Models\DTO {

	if (! class_exists('WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig')) {
		final class ModelConfig
		{

			/**
			 * @param array<string, mixed> $config
			 */
			public function __construct(private array $config = []) {}

			/**
			 * @param array<string, mixed> $config
			 */
			public static function fromArray(array $config): self
			{
				return new self($config);
			}

			/**
			 * @return array<string, mixed>
			 */
			public function toArray(): array
			{
				return $this->config;
			}
		}
	}
}

namespace WordPress\AiClient {

	use FlavorAgent\Tests\Support\WordPressTestState;

	if (! class_exists('WordPress\\AiClient\\AiClient')) {
		final class AiClient
		{

			public static function defaultRegistry(): FakeProviderModelRegistry
			{
				return new FakeProviderModelRegistry();
			}
		}
	}

	if (! class_exists('WordPress\\AiClient\\FakeProviderModelRegistry')) {
		final class FakeProviderModelRegistry
		{

			public function getProviderModel(string $provider, string $model): object
			{
				if (WordPressTestState::$ai_client_model_resolution_error instanceof \WP_Error) {
					return WordPressTestState::$ai_client_model_resolution_error;
				}

				return (object) [
					'provider' => $provider,
					'model'    => $model,
				];
			}
		}
	}
}

namespace WordPress\AI\Abstracts {

	if (! class_exists('WordPress\\AI\\Abstracts\\Abstract_Ability')) {
		abstract class Abstract_Ability
		{
			/** @var array<string, mixed> */
			protected array $properties;

			public function __construct(protected string $name, array $properties = [])
			{
				$this->properties = $properties;
			}

			public function get_system_instruction(?string $filename = null, array $data = []): string
			{
				unset($filename);

				$instruction = '';

				if (method_exists($this, 'guideline_categories') && function_exists('WordPress\\AI\\format_guidelines_for_prompt')) {
					$instruction = (string) \WordPress\AI\format_guidelines_for_prompt(
						$this->guideline_categories(),
						is_string($data['block_name'] ?? null) && '' !== $data['block_name']
							? $data['block_name']
							: null
					);
				}

				return (string) \apply_filters(
					'wpai_system_instruction',
					$instruction,
					is_string($data['ability'] ?? null) && '' !== $data['ability'] ? $data['ability'] : $this->name,
					$data
				);
			}

			public function input_schema(): array
			{
				return [];
			}

			public function output_schema(): array
			{
				return [];
			}

			public function execute_callback(mixed $input): mixed
			{
				return $input;
			}

			public function permission_callback(mixed $input = null): bool
			{
				unset($input);

				return true;
			}

			public function meta(): array
			{
				return [];
			}

			public function category(): string
			{
				return '';
			}

			protected function guideline_categories(): array
			{
				return [];
			}
		}
	}

	if (! class_exists('WordPress\\AI\\Abstracts\\Abstract_Feature')) {
		abstract class Abstract_Feature
		{
			final public function __construct() {}

			public static function get_id(): string
			{
				return '';
			}

			abstract protected function load_metadata(): array;

			abstract public function register(): void;

			public function get_label(): string
			{
				return (string) ($this->load_metadata()['label'] ?? '');
			}

			public function get_description(): string
			{
				return (string) ($this->load_metadata()['description'] ?? '');
			}

			public function get_category(): string
			{
				return (string) ($this->load_metadata()['category'] ?? '');
			}

			public function get_stability(): string
			{
				return (string) ($this->load_metadata()['stability'] ?? 'experimental');
			}
		}
	}
}

namespace WordPress\AI\Experiments {

	if (! class_exists('WordPress\\AI\\Experiments\\Experiment_Category')) {
		final class Experiment_Category
		{
			public const EDITOR = 'editor';
			public const ADMIN  = 'admin';
		}
	}
}

namespace WordPress\AI {

	use FlavorAgent\Tests\Support\WordPressTestState;

	final class FakeAIService
	{

		/**
		 * @param array<string, mixed> $options
		 */
		public function create_textgen_prompt(?string $prompt = null, array $options = []): \WP_AI_Client_Prompt_Builder
		{
			if (null !== WordPressTestState::$ai_service_call_throws) {
				throw WordPressTestState::$ai_service_call_throws;
			}

			WordPressTestState::$ai_service_calls[] = [
				'prompt'  => is_string($prompt) ? $prompt : '',
				'options' => $options,
			];

			$builder = \wp_ai_client_prompt($prompt);

			if (
				isset($options['system_instruction'])
				&& is_callable([$builder, 'using_system_instruction'])
			) {
				$builder = $builder->using_system_instruction((string) $options['system_instruction']);
			}

			return $builder;
		}
	}

	if (! function_exists('WordPress\\AI\\get_ai_service')) {
		function get_ai_service(): FakeAIService
		{
			return new FakeAIService();
		}
	}

	if (! function_exists('WordPress\\AI\\format_guidelines_for_prompt')) {
		function format_guidelines_for_prompt(array $categories, ?string $block_name = null): string
		{
			WordPressTestState::$wpai_guideline_calls[] = [
				'categories' => array_values(array_map('strval', $categories)),
				'blockName'  => $block_name,
			];

			return WordPressTestState::$wpai_formatted_guidelines;
		}
	}

	if (! function_exists('WordPress\\AI\\get_preferred_models_for_text_generation')) {
		function get_preferred_models_for_text_generation(): array
		{
			return WordPressTestState::$preferred_text_models;
		}
	}

	if (! function_exists('WordPress\\AI\\has_ai_credentials')) {
		function has_ai_credentials(): bool
		{
			$connectors      = \function_exists('wp_get_connectors') ? \wp_get_connectors() : [];
			$has_credentials = false;

			foreach ($connectors as $connector_data) {
				if (! is_array($connector_data) || 'ai_provider' !== (string) ($connector_data['type'] ?? '')) {
					continue;
				}

				$auth         = is_array($connector_data['authentication'] ?? null) ? $connector_data['authentication'] : [];
				$setting_name = is_string($auth['setting_name'] ?? null) ? $auth['setting_name'] : '';

				if ('' !== $setting_name && '' !== (string) \get_option($setting_name, '')) {
					$has_credentials = true;
					break;
				}
			}

			return (bool) \apply_filters('wpai_has_ai_credentials', $has_credentials, $connectors);
		}
	}

	if (! function_exists('WordPress\\AI\\has_valid_ai_credentials')) {
		function has_valid_ai_credentials(): bool
		{
			if (! has_ai_credentials()) {
				return false;
			}

			$valid = \apply_filters('wpai_pre_has_valid_credentials_check', null);

			if (null !== $valid) {
				return (bool) $valid;
			}

			return \wp_ai_client_prompt('Test')->is_supported_for_text_generation();
		}
	}
}

namespace WordPress\AI_Client {

	use FlavorAgent\Tests\Support\WordPressTestState;

	final class AI_Client
	{

		public static function prompt_with_wp_error(string $text): FakePromptBuilder
		{
			WordPressTestState::$last_ai_client_prompt = [
				'text' => $text,
				'transport' => 'legacy_class',
			];

			return new FakePromptBuilder();
		}
	}

	final class FakePromptBuilder
	{

		public function using_system_instruction(string $text): self
		{
			WordPressTestState::$last_ai_client_prompt['system'] = $text;

			return $this;
		}

		public function using_provider(string $provider): self
		{
			WordPressTestState::$last_ai_client_prompt['provider'] = $provider;

			return $this;
		}

		public function using_model(object $model): self
		{
			WordPressTestState::$last_ai_client_prompt['provider'] = (string) ($model->provider ?? '');
			WordPressTestState::$last_ai_client_prompt['model']    = (string) ($model->model ?? '');

			return $this;
		}

		public function using_reasoning_effort($reasoning): self
		{
			WordPressTestState::$last_ai_client_prompt['reasoning'] = is_array($reasoning)
				? (string) ($reasoning['effort'] ?? '')
				: (string) $reasoning;

			return $this;
		}

		public function using_reasoning($reasoning): self
		{
			WordPressTestState::$last_ai_client_prompt['reasoning'] = is_array($reasoning)
				? (string) ($reasoning['effort'] ?? '')
				: (string) $reasoning;

			return $this;
		}

		public function as_json_response(?array $schema): self
		{
			WordPressTestState::$last_ai_client_prompt['json_schema'] = is_array($schema)
				? $schema
				: null;

			return $this;
		}

		public function is_supported_for_text_generation(): bool
		{
			return WordPressTestState::ai_client_prompt_supports_text_generation(
				WordPressTestState::$last_ai_client_prompt
			);
		}

		public function generate_text(): mixed
		{
			WordPressTestState::$last_http_request_args = apply_filters(
				'http_request_args',
				['timeout' => 30],
				'https://api.openai.com/v1/responses'
			);

			if (null !== WordPressTestState::$ai_client_generate_text_throws) {
				throw WordPressTestState::$ai_client_generate_text_throws;
			}

			$explicit = WordPressTestState::$ai_client_generate_text_result;

			if ('' !== $explicit && null !== $explicit) {
				return $explicit;
			}

			$translated = \FlavorAgent\Tests\Support\WordPressTestState::consume_pending_chat_response_for_ai_client(
				(object) WordPressTestState::$last_ai_client_prompt
			);

			return null !== $translated ? $translated : $explicit;
		}

		public function generate_text_result(): mixed
		{
			return $this->generate_text();
		}
	}
}

namespace {

	use FlavorAgent\Tests\Support\WordPressTestState;

	if (! class_exists('WP_AI_Client_Prompt_Builder')) {
		class WP_AI_Client_Prompt_Builder
		{

			/**
			 * @var array<string, mixed>
			 */
			private array $state = [];

			/**
			 * @param array<string, mixed> $state
			 */
			public function __construct(array $state = [])
			{
				$this->state = $state;
				$this->sync_state();
			}

			public function __call(string $name, array $arguments)
			{
				switch ($name) {
					case 'using_system_instruction':
						$this->state['system'] = (string) ($arguments[0] ?? '');
						$this->sync_state();

						return $this;
					case 'using_provider':
						$this->state['provider'] = (string) ($arguments[0] ?? '');
						$this->sync_state();

						return $this;
					case 'using_model':
						$model = $arguments[0] ?? null;

						if (is_object($model)) {
							$this->state['provider'] = (string) ($model->provider ?? '');
							$this->state['model']    = (string) ($model->model ?? '');
						}
						$this->sync_state();

						return $this;
					case 'using_reasoning_effort':
					case 'using_reasoning':
						$reasoning = $arguments[0] ?? '';

						$this->state['reasoning'] = is_array($reasoning)
							? (string) ($reasoning['effort'] ?? '')
							: (string) $reasoning;
						$this->sync_state();

						return $this;
					case 'using_model_config':
						$config = $arguments[0] ?? null;

						if (is_object($config) && is_callable([$config, 'toArray'])) {
							$config = $config->toArray();
						}

						if (is_array($config)) {
							$this->state['model_config']  = $config;
							$this->state['customOptions'] = is_array($config['customOptions'] ?? null)
								? $config['customOptions']
								: [];
						}
						$this->sync_state();

						return $this;
					case 'using_model_preference':
						$this->state['model_preferences'] = $arguments;
						$this->sync_state();

						return $this;
					case 'as_json_response':
						$this->state['json_schema'] = is_array($arguments[0] ?? null)
							? $arguments[0]
							: null;
						$this->sync_state();

						return $this;
					case 'is_supported_for_text_generation':
						$this->sync_state();

						if ((bool) apply_filters('wp_ai_client_prevent_prompt', false, $this)) {
							return false;
						}

						return WordPressTestState::ai_client_prompt_supports_text_generation($this->state);
					case 'generate_text':
					case 'generate_text_result':
						$this->sync_state();
						WordPressTestState::$last_http_request_args = apply_filters(
							'http_request_args',
							['timeout' => 30],
							'https://api.openai.com/v1/responses'
						);

						if (null !== WordPressTestState::$ai_client_generate_text_throws) {
							throw WordPressTestState::$ai_client_generate_text_throws;
						}

						if ((bool) apply_filters('wp_ai_client_prevent_prompt', false, $this)) {
							throw new \WordPress\AI_Client\Builders\Exception\Prompt_Prevented_Exception(
								'Prompt execution was prevented by a filter.'
							);
						}

						$explicit = WordPressTestState::$ai_client_generate_text_result;

						if ('' !== $explicit && null !== $explicit) {
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
						esc_html(sanitize_text_field($name))
					)
				);
			}

			private function sync_state(): void
			{
				WordPressTestState::$last_ai_client_prompt = $this->state;
			}
		}
	}

	if (! function_exists('wp_ai_client_prompt')) {
		function wp_ai_client_prompt($prompt = null): WP_AI_Client_Prompt_Builder
		{
			return new WP_AI_Client_Prompt_Builder(
				[
					'text'      => is_string($prompt) ? $prompt : '',
					'transport' => 'core_function',
				]
			);
		}
	}

	if (! class_exists('WP_Error')) {
		class WP_Error
		{

			/**
			 * @var array<string, string[]>
			 */
			public array $errors = [];

			/**
			 * @var array<string, mixed>
			 */
			public array $error_data = [];

			public function __construct(string $code = '', string $message = '', $data = null)
			{
				if ('' === $code) {
					return;
				}

				$this->errors[$code] = [$message];

				if (null !== $data) {
					$this->error_data[$code] = $data;
				}
			}

			public function get_error_code(): string
			{
				$code = array_key_first($this->errors);

				return is_string($code) ? $code : '';
			}

			public function get_error_message(string $code = ''): string
			{
				$resolved_code = '' !== $code ? $code : $this->get_error_code();

				return $this->errors[$resolved_code][0] ?? '';
			}

			public function get_error_data(string $code = '')
			{
				$resolved_code = '' !== $code ? $code : $this->get_error_code();

				return $this->error_data[$resolved_code] ?? null;
			}
		}
	}

	if (! class_exists('WP_REST_Request')) {
		class WP_REST_Request
		{

			/**
			 * @var array<string, mixed>
			 */
			private array $params = [];

			private string $method;

			private string $route;

			public function __construct(string $method = 'GET', string $route = '/')
			{
				$this->method = strtoupper($method);
				$this->route  = $route;
			}

			public function get_param(string $key)
			{
				return $this->params[$key] ?? null;
			}

			public function has_param(string $key): bool
			{
				return array_key_exists($key, $this->params);
			}

			public function set_param(string $key, $value): void
			{
				$this->params[$key] = $value;
			}

			public function get_method(): string
			{
				return $this->method;
			}

			public function get_route(): string
			{
				return $this->route;
			}
		}
	}

	if (! class_exists('WP_REST_Response')) {
		class WP_REST_Response
		{

			/**
			 * @var mixed
			 */
			private $data;

			private int $status;

			/** @var array<string, string> */
			private array $headers = [];

			public function __construct($data = null, int $status = 200)
			{
				$this->data   = $data;
				$this->status = $status;
			}

			public function get_data()
			{
				return $this->data;
			}

			public function get_status(): int
			{
				return $this->status;
			}

			public function header(string $key, string $value, bool $replace = true): void
			{
				unset($replace);
				$this->headers[$key] = $value;
			}

			/** @return array<string, string> */
			public function get_headers(): array
			{
				return $this->headers;
			}
		}
	}

	if (! class_exists('WP_CLI')) {
		class WP_CLI
		{

			public static function add_command(string $name, $callable, array $args = []): bool
			{
				WordPressTestState::$wp_cli_commands[$name] = [
					'callable' => $callable,
					'args'     => $args,
				];

				return true;
			}

			public static function line(string $message = ''): void
			{
				WordPressTestState::$wp_cli_messages[] = [
					'type'    => 'line',
					'message' => $message,
				];
			}

			public static function success(string $message): void
			{
				WordPressTestState::$wp_cli_messages[] = [
					'type'    => 'success',
					'message' => $message,
				];
			}

			public static function error(string $message, bool $exit = true): void
			{
				WordPressTestState::$wp_cli_messages[] = [
					'type'    => 'error',
					'message' => $message,
				];

				if ($exit) {
					throw new \RuntimeException($message);
				}
			}

			public static function halt(int $exit_code): void
			{
				WordPressTestState::$wp_cli_exit_code = $exit_code;
				$messages = WordPressTestState::$wp_cli_messages;
				$last = end($messages);
				$message = is_array($last) ? (string) ($last['message'] ?? '') : '';

				throw new \RuntimeException($message);
			}
		}
	}

	if (! defined('OBJECT')) {
		define('OBJECT', 'OBJECT');
	}

	if (! defined('ARRAY_A')) {
		define('ARRAY_A', 'ARRAY_A');
	}

	if (! defined('MINUTE_IN_SECONDS')) {
		define('MINUTE_IN_SECONDS', 60);
	}

	if (! defined('WP_TEMPLATE_PART_AREA_UNCATEGORIZED')) {
		define('WP_TEMPLATE_PART_AREA_UNCATEGORIZED', 'uncategorized');
	}

	if (! class_exists('wpdb')) {
		class wpdb
		{

			public string $prefix = 'wp_';

			public string $options = 'wp_options';

			public string $posts = 'wp_posts';

			public string $postmeta = 'wp_postmeta';

			public string $terms = 'wp_terms';

			public string $term_taxonomy = 'wp_term_taxonomy';

			public string $term_relationships = 'wp_term_relationships';

			public int $insert_id = 0;

			public string $last_query = '';

			public string $func_call = '';

			public string $last_error = '';

			public array $queries = [];

			private bool $return_filtered_query = false;

			private bool $errors_suppressed = false;

			public function suppress_errors(bool $suppress = true): bool
			{
				$previous = $this->errors_suppressed;
				$this->errors_suppressed = $suppress;

				return $previous;
			}

			public function get_charset_collate(): string
			{
				return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}

			public function esc_like(string $text): string
			{
				return addcslashes($text, '_%\\');
			}

			public function prepare(string $query, ...$args): string
			{
				$flat_args = [];

				foreach ($args as $arg) {
					if (is_array($arg)) {
						$flat_args = array_merge($flat_args, $arg);
					} else {
						$flat_args[] = $arg;
					}
				}

				foreach ($flat_args as $arg) {
					if (! preg_match('/%[sdi]/', $query, $matches)) {
						break;
					}

					$placeholder = (string) ($matches[0] ?? '%s');
					if ('%d' === $placeholder) {
						$replacement = (string) (int) $arg;
					} elseif ('%i' === $placeholder) {
						$replacement = str_replace('`', '``', (string) $arg);
					} else {
						$replacement = "'" . str_replace("'", "\\'", (string) $arg) . "'";
					}

					$query = preg_replace(
						'/' . preg_quote($placeholder, '/') . '/',
						$replacement,
						$query,
						1
					) ?? $query;
				}

				return $query;
			}

			public function query(string $query)
			{
				$filter_only                 = $this->return_filtered_query;
				$this->return_filtered_query = false;
				$query = apply_filters('query', $query);
				WordPressTestState::$db_queries[] = $query;
				$this->last_query = $query;
				$this->func_call  = '$wpdb->query("' . $query . '")';
				$this->queries[]  = [$query, 0.0, 'test'];

				if ('' === trim($query)) {
					return false;
				}

				if ($filter_only) {
					return $query;
				}

				if (
					preg_match(
						"/^UPDATE ([^\\s]+) SET post_content = UNHEX\\('([a-f0-9]*)'\\) WHERE ID = ([0-9]+) AND HEX\\(post_type\\) = '([a-f0-9]*)' AND OCTET_LENGTH\\(post_content\\) = ([0-9]+) AND MD5\\(CONCAT\\('([a-f0-9]+)', HEX\\(post_content\\), '([a-f0-9]+)'\\)\\) = '([a-f0-9]{32})' AND MD5\\(CONCAT\\('([a-f0-9]+)', HEX\\(post_content\\), '([a-f0-9]+)'\\)\\) = '([a-f0-9]{32})' \\/\\* flavor-agent-content-restore-[a-f0-9]+ \\*\\/$/i",
						$query,
						$matches
					)
				) {
					$table           = (string) ($matches[1] ?? '');
					$before_content  = hex2bin((string) ($matches[2] ?? ''));
					$post_id         = (int) ($matches[3] ?? 0);
					$post_type       = hex2bin((string) ($matches[4] ?? ''));
					$expected_length = (int) ($matches[5] ?? -1);
					$prefix_one      = (string) ($matches[6] ?? '');
					$suffix_one      = (string) ($matches[7] ?? '');
					$digest_one      = (string) ($matches[8] ?? '');
					$prefix_two      = (string) ($matches[9] ?? '');
					$suffix_two      = (string) ($matches[10] ?? '');
					$digest_two      = (string) ($matches[11] ?? '');
					$post            = WordPressTestState::$posts[$post_id] ?? null;

					if ($this->posts !== $table || false === $before_content || false === $post_type || ! is_object($post)) {
						return 0;
					}

					$written_content = (string) ($post->post_content ?? '');

					$hook = WordPressTestState::$before_post_content_compensation;

					if (is_callable($hook)) {
						WordPressTestState::$before_post_content_compensation = null;
						$hook($post_id, $written_content, $before_content);
					}

					$post = WordPressTestState::$posts[$post_id] ?? null;

					$current_content = (string) ($post->post_content ?? '');
					$current_hex     = strtoupper(bin2hex($current_content));

					if (
						! is_object($post)
						|| $post_type !== (string) ($post->post_type ?? '')
						|| $expected_length !== strlen($current_content)
						|| ! hash_equals($digest_one, md5($prefix_one . $current_hex . $suffix_one))
						|| ! hash_equals($digest_two, md5($prefix_two . $current_hex . $suffix_two))
					) {
						return 0;
					}

					$post->post_content = $before_content;

					return 1;
				}

				if (preg_match('/CREATE TABLE\s+([^\s(]+)/i', $query, $matches)) {
					$table = (string) ($matches[1] ?? '');

					if ('' !== $table && ! isset(WordPressTestState::$db_tables[$table])) {
						WordPressTestState::$db_tables[$table] = [];
					}
				}

				if (preg_match('/DROP TABLE IF EXISTS\s+([^\s]+)/i', $query, $matches)) {
					$table = (string) ($matches[1] ?? '');
					unset(WordPressTestState::$db_tables[$table]);

					return 1;
				}

				if (preg_match('/DELETE FROM\s+([^\s]+)\s+WHERE\s+created_at\s*<\s*\'([^\']+)\'/i', $query, $matches)) {
					$table  = (string) ($matches[1] ?? '');
					$cutoff = (string) ($matches[2] ?? '');
					$preserve_active_claims = str_contains(
						$query,
						'CONVERT(HEX(execution_result) USING utf8mb4) REGEXP'
					);

					if (isset(WordPressTestState::$db_tables[$table])) {
						$before_count = count(WordPressTestState::$db_tables[$table]);
						WordPressTestState::$db_tables[$table] = array_values(
							array_filter(
								WordPressTestState::$db_tables[$table],
								static fn(array $row): bool => (string) ($row['created_at'] ?? '') >= $cutoff
									|| (
										$preserve_active_claims
										&& 1 === preg_match('/^claim:[a-f0-9]{24}$/', (string) ($row['execution_result'] ?? ''))
									)
							)
						);

						return $before_count - count(WordPressTestState::$db_tables[$table]);
					}

					return 0;
				}

				return 1;
			}

			public function insert(string $table, array $data, array $format = [])
			{
				if (str_ends_with($table, '_postmeta')) {
					$post_id  = (int) ($data['post_id'] ?? 0);
					$meta_key = (string) ($data['meta_key'] ?? '');
					$query    = sprintf(
						"INSERT INTO %s (`post_id`, `meta_key`, `meta_value`) VALUES (%d, '%s', '%s')",
						$table,
						$post_id,
						addslashes($meta_key),
						addslashes((string) ($data['meta_value'] ?? ''))
					);

					if (false === $this->query($query)) {
						return false;
					}

					$rows    = $this->postmeta_rows($table);
					$meta_id = 'wp_postmeta' === $table
						? flavor_agent_test_meta_id($post_id, $meta_key)
						: ++WordPressTestState::$db_insert_id;
					$rows[]  = [
						'meta_id'    => $meta_id,
						'post_id'    => $post_id,
						'meta_key'   => $meta_key,
						'meta_value' => $data['meta_value'] ?? '',
					];
					$this->store_postmeta_rows($table, $rows);
					$this->insert_id = $meta_id;

					return 1;
				}

				if ($this->options === $table || 'wp_options' === $table) {
					$option_name = (string) ($data['option_name'] ?? '');
					$hook        = WordPressTestState::$before_materialization_lock_insert;

					if (
						str_starts_with($option_name, 'flavor_agent_materialization_lock_')
						&& is_callable($hook)
					) {
						WordPressTestState::$before_materialization_lock_insert = null;
						$hook();
					}

					$site_options = 'wp_options' === $table
						? WordPressTestState::$options
						: array_column(WordPressTestState::$db_tables[$table] ?? [], 'option_value', 'option_name');

					if (WordPressTestState::$option_insert_fails || '' === $option_name || array_key_exists($option_name, $site_options)) {
						return false;
					}

					if ('wp_options' === $table) {
						$option_value = $data['option_value'] ?? '';
						if (is_string($option_value)) {
							$unserialized = @unserialize($option_value);
							if (false !== $unserialized || 'b:0;' === $option_value) {
								$option_value = $unserialized;
							}
						}
						WordPressTestState::$options[$option_name]         = $option_value;
						WordPressTestState::$option_autoload[$option_name] = $data['autoload'] ?? 'no';
					} else {
						WordPressTestState::$db_tables[$table][] = $data;
					}

					return 1;
				}

				if (str_ends_with($table, '_posts')) {
					$columns = array_map(
						static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
						array_map('strval', array_keys($data))
					);
					$values = array_map(
						static fn($value): string => "'" . addslashes((string) $value) . "'",
						array_values($data)
					);
					$query = sprintf(
						'INSERT INTO %s (%s) VALUES (%s)',
						$table,
						implode(', ', $columns),
						implode(', ', $values)
					);

					if (false === $this->query($query)) {
						return false;
					}

					$hook = WordPressTestState::$after_database_post_insert;

					if (is_callable($hook)) {
						WordPressTestState::$after_database_post_insert = null;
						$hook($table, $data);
					}

					return 1;
				}

				if ($this->term_relationships === $table) {
					$query = sprintf(
						'INSERT INTO %s (`object_id`, `term_taxonomy_id`) VALUES (%d, %d)',
						$table,
						(int) ($data['object_id'] ?? 0),
						(int) ($data['term_taxonomy_id'] ?? 0)
					);

					if (false === $this->query($query)) {
						return false;
					}

					WordPressTestState::$db_tables[$table][] = $data;

					return 1;
				}

				WordPressTestState::$db_insert_id += 1;
				$row = array_merge(
					[
						'id' => WordPressTestState::$db_insert_id,
					],
					$data
				);

				if (! isset(WordPressTestState::$db_tables[$table])) {
					WordPressTestState::$db_tables[$table] = [];
				}

				WordPressTestState::$db_tables[$table][] = $row;
				$this->insert_id = WordPressTestState::$db_insert_id;

				return 1;
			}

			public function delete(
				string $table,
				array $where,
				array $where_format = []
			) {
				unset($where_format);

				if ($this->options === $table || 'wp_options' === $table) {
					$option_name  = (string) ($where['option_name'] ?? '');
					$option_value = (string) ($where['option_value'] ?? '');

					if (WordPressTestState::$option_delete_fails) {
						return false;
					}

					if ('wp_options' !== $table) {
						$rows    = WordPressTestState::$db_tables[$table] ?? [];
						$deleted = false;

						foreach ($rows as $index => $row) {
							if (
								$option_name !== (string) ($row['option_name'] ?? '')
								|| $option_value !== (string) ($row['option_value'] ?? '')
							) {
								continue;
							}

							unset($rows[$index]);
							$deleted = true;
							break;
						}

						if (! $deleted) {
							return 0;
						}

						WordPressTestState::$db_tables[$table] = array_values($rows);
					} else {
						if (
							! array_key_exists($option_name, WordPressTestState::$options)
							|| (string) WordPressTestState::$options[$option_name] !== $option_value
						) {
							return 0;
						}

						unset(
							WordPressTestState::$options[$option_name],
							WordPressTestState::$option_autoload[$option_name]
						);
					}

					$hook = WordPressTestState::$after_materialization_lock_delete;

					if (
						str_starts_with($option_name, 'flavor_agent_materialization_lock_')
						&& is_callable($hook)
					) {
						WordPressTestState::$after_materialization_lock_delete = null;
						$hook();
					}

					return 1;
				}

				if (! isset(WordPressTestState::$db_tables[$table])) {
					return 0;
				}

				$deleted = 0;

				WordPressTestState::$db_tables[$table] = array_values(
					array_filter(
						WordPressTestState::$db_tables[$table],
						function (array $row) use ($where, &$deleted): bool {
							if (! $this->row_matches($row, $where)) {
								return true;
							}

							++$deleted;

							return false;
						}
					)
				);

				return $deleted;
			}

			public function update(
				string $table,
				array $data,
				array $where,
				array $format = [],
				array $where_format = []
			) {
				if (str_ends_with($table, '_postmeta')) {
					$post_id  = (int) ($where['post_id'] ?? 0);
					$meta_key = (string) ($where['meta_key'] ?? '');
					$query    = sprintf(
						"UPDATE %s SET `meta_value` = '%s' WHERE `post_id` = %d AND `meta_key` = '%s'",
						$table,
						addslashes((string) ($data['meta_value'] ?? '')),
						$post_id,
						addslashes($meta_key)
					);
					$this->return_filtered_query = true;

					try {
						$query = $this->query($query);
					} finally {
						$this->return_filtered_query = false;
					}

					if (false === $query || '' === $query) {
						return false;
					}

					if (
						str_contains($query, 'flavor-agent-existing-meta-')
						&& ! $this->guarded_meta_update_matches(
							$query,
							$post_id,
							$meta_key,
							(string) ($data['meta_value'] ?? '')
						)
					) {
						$this->record_query_error($query);

						return false;
					}

					$rows    = $this->postmeta_rows($table);
					$updated = 0;

					foreach ($rows as $index => $row) {
						if (! $this->row_matches($row, $where)) {
							continue;
						}

						$rows[$index] = array_merge($row, $data);
						++$updated;
					}

					$this->store_postmeta_rows($table, $rows);

					return $updated;
				}

				if ($this->options === $table || 'wp_options' === $table) {
					$option_name = (string) ($where['option_name'] ?? '');

					if ('wp_options' === $table) {
						if (! array_key_exists($option_name, WordPressTestState::$options)) {
							return 0;
						}

						$option_value = $data['option_value'] ?? WordPressTestState::$options[$option_name];
						if (is_string($option_value)) {
							$unserialized = @unserialize($option_value);
							if (false !== $unserialized || 'b:0;' === $option_value) {
								$option_value = $unserialized;
							}
						}

						if (WordPressTestState::$options[$option_name] === $option_value) {
							return 0;
						}

						WordPressTestState::$options[$option_name] = $option_value;

						return 1;
					}

					foreach (WordPressTestState::$db_tables[$table] ?? [] as $index => $row) {
						if ($option_name !== (string) ($row['option_name'] ?? '')) {
							continue;
						}

						WordPressTestState::$db_tables[$table][$index] = array_merge($row, $data);

						return 1;
					}

					return 0;
				}

				if ($this->posts === $table) {
					$post_id = (int) ($where['ID'] ?? 0);
					$post    = WordPressTestState::$posts[$post_id] ?? null;

					$set_clauses = [];

					foreach ($data as $key => $value) {
						$set_clauses[] = sprintf("`%s` = '%s'", (string) $key, addslashes((string) $value));
					}

					$query = sprintf(
						'UPDATE %s SET %s WHERE `ID` = %d',
						$table,
						implode(', ', $set_clauses),
						$post_id
					);
					$this->return_filtered_query = true;

					try {
						$query = $this->query($query);
					} finally {
						$this->return_filtered_query = false;
					}

					if (false === $query || '' === $query) {
						return false;
					}

					if (str_contains($query, 'AND 1 = 0')) {
						return 0;
					}

					$is_conditional = preg_match(
						"/^UPDATE `?([^`\\s]+)`? SET `ID` = CASE WHEN HEX\\(`post_type`\\) = '([a-f0-9]*)' AND HEX\\(`post_name`\\) = '([a-f0-9]*)' AND HEX\\(`post_status`\\) = '([a-f0-9]*)' AND HEX\\(`post_password`\\) = '([a-f0-9]*)' AND HEX\\(`post_modified`\\) = '([a-f0-9]*)' AND HEX\\(`post_modified_gmt`\\) = '([a-f0-9]*)' AND OCTET_LENGTH\\(`post_content`\\) = ([0-9]+) AND MD5\\(CONCAT\\('([a-f0-9]+)', HEX\\(`post_content`\\), '([a-f0-9]+)'\\)\\) = '([a-f0-9]{32})' AND MD5\\(CONCAT\\('([a-f0-9]+)', HEX\\(`post_content`\\), '([a-f0-9]+)'\\)\\) = '([a-f0-9]{32})' THEN `ID` ELSE ABS\\(-9223372036854775808\\) END, .* WHERE `ID` = ([0-9]+) \\/\\* flavor-agent-existing-write-[a-f0-9]+ \\*\\/$/is",
						$query,
						$matches
					);

					if (1 === $is_conditional) {
						$guard_table      = (string) ($matches[1] ?? '');
						$expected_type    = hex2bin((string) ($matches[2] ?? ''));
						$expected_name    = hex2bin((string) ($matches[3] ?? ''));
						$expected_status  = hex2bin((string) ($matches[4] ?? ''));
						$expected_password = hex2bin((string) ($matches[5] ?? ''));
						$expected_modified = hex2bin((string) ($matches[6] ?? ''));
						$expected_modified_gmt = hex2bin((string) ($matches[7] ?? ''));
						$expected_length  = (int) ($matches[8] ?? -1);
						$prefix_one       = (string) ($matches[9] ?? '');
						$suffix_one       = (string) ($matches[10] ?? '');
						$digest_one       = (string) ($matches[11] ?? '');
						$prefix_two       = (string) ($matches[12] ?? '');
						$suffix_two       = (string) ($matches[13] ?? '');
						$digest_two       = (string) ($matches[14] ?? '');
						$guard_post_id    = (int) ($matches[15] ?? 0);
						$hook             = WordPressTestState::$before_conditional_post_content_write;

						if (is_callable($hook)) {
							WordPressTestState::$before_conditional_post_content_write = null;
							$hook($post_id, $data);
						}

						if (
							$this->posts !== $guard_table
							|| $post_id !== $guard_post_id
							|| false === $expected_type
							|| false === $expected_name
							|| false === $expected_status
							|| false === $expected_password
							|| false === $expected_modified
							|| false === $expected_modified_gmt
						) {
							return false;
						}

						$post = WordPressTestState::$posts[$post_id] ?? null;

						if (! is_object($post)) {
							return 0;
						}

						$current_content = (string) ($post->post_content ?? '');
						$current_hex     = strtoupper(bin2hex($current_content));

						if (
							$expected_type !== (string) ($post->post_type ?? '')
							|| $expected_name !== (string) ($post->post_name ?? '')
							|| $expected_status !== (string) ($post->post_status ?? '')
							|| $expected_password !== (string) ($post->post_password ?? '')
							|| $expected_modified !== (string) ($post->post_modified ?? '')
							|| $expected_modified_gmt !== (string) ($post->post_modified_gmt ?? '')
							|| $expected_length !== strlen($current_content)
							|| ! hash_equals($digest_one, md5($prefix_one . $current_hex . $suffix_one))
							|| ! hash_equals($digest_two, md5($prefix_two . $current_hex . $suffix_two))
						) {
							$this->record_query_error($query);

							return false;
						}
					}

					$post = WordPressTestState::$posts[$post_id] ?? null;

					if (! is_object($post)) {
						return 0;
					}

					if (1 === $is_conditional) {
						$post->post_content = (string) ($data['post_content'] ?? '');
						$post->post_modified = (string) ($data['post_modified'] ?? '');
						$post->post_modified_gmt = (string) ($data['post_modified_gmt'] ?? '');
					} else {
						foreach ($data as $key => $value) {
							if (property_exists($post, (string) $key)) {
								$post->{$key} = $value;
							}
						}
					}

					if (1 === $is_conditional) {
						$hook = WordPressTestState::$after_wp_update_post;

						if (is_callable($hook)) {
							WordPressTestState::$after_wp_update_post = null;
							$hook($post_id, $data);
						}
					}

					return 1;
				}

				if (str_ends_with($table, '_flavor_agent_activity')) {
					$hook = WordPressTestState::$before_activity_table_update;

					if (is_callable($hook)) {
						WordPressTestState::$before_activity_table_update = null;
						$override = $hook($table, $data, $where);

						if (null !== $override) {
							return $override;
						}
					}
				}

				if (! isset(WordPressTestState::$db_tables[$table])) {
					return 0;
				}

				$updated = 0;

				foreach (WordPressTestState::$db_tables[$table] as $index => $row) {
					if (! $this->row_matches($row, $where)) {
						continue;
					}

					WordPressTestState::$db_tables[$table][$index] = array_merge($row, $data);
					++$updated;
				}

				return $updated;
			}

			private function record_query_error(string $query): void
			{
				global $EZSQL_ERROR;

				$this->last_error = 'Guarded query rejected the stale row.';
				$EZSQL_ERROR      = is_array($EZSQL_ERROR ?? null) ? $EZSQL_ERROR : [];
				$EZSQL_ERROR[]    = [
					'query'     => $query,
					'error_str' => $this->last_error,
				];
			}

			public function get_row(string $query, string $output = OBJECT)
			{
				$results = $this->get_results($query, $output);

				return $results[0] ?? null;
			}

			public function get_var(string $query)
			{
				WordPressTestState::$db_queries[] = $query;

				if (
					preg_match(
						'/SELECT\s+option_value\s+FROM\s+`?([^`\s]+)`?\s+WHERE\s+option_name\s*=\s*\'([^\']+)\'/i',
						$query,
						$matches
					)
				) {
					$table       = (string) ($matches[1] ?? '');
					$option_name = stripslashes((string) ($matches[2] ?? ''));

					if ('wp_options' === $table) {
						$value = WordPressTestState::$options[$option_name] ?? null;

						return is_array($value) || is_object($value) ? serialize($value) : $value;
					}

					foreach (WordPressTestState::$db_tables[$table] ?? [] as $row) {
						if ($option_name === (string) ($row['option_name'] ?? '')) {
							return $row['option_value'] ?? null;
						}
					}

					return null;
				}

				if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $query, $matches)) {
					$table = stripslashes((string) ($matches[1] ?? ''));

					return array_key_exists($table, WordPressTestState::$db_tables)
						? $table
						: null;
				}

				if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+/i', $query)) {
					$select_all_query = preg_replace(
						'/SELECT\s+COUNT\(\*\)/i',
						'SELECT *',
						$query,
						1
					) ?? $query;

					return count($this->get_results($select_all_query, ARRAY_A));
				}

				return null;
			}

			public function get_col(string $query): array
			{
				$rows = $this->get_results($query, ARRAY_A);

				if ([] === $rows || ! is_array($rows[0] ?? null)) {
					return [];
				}

				$column = array_key_first($rows[0]);

				return null === $column
					? []
					: array_values(array_map(static fn(array $row) => $row[$column] ?? null, $rows));
			}

			public function get_results(string $query, string $output = OBJECT): array
			{
				WordPressTestState::$db_queries[] = $query;

				if (
					preg_match(
						'/^\s*SELECT\s+t\.name\s+AS\s+term_name\s+FROM\s+`?([^`\s]+)`?\s+AS\s+tr\s+INNER\s+JOIN\s+`?([^`\s]+)`?\s+AS\s+tt\s+ON\s+tt\.term_taxonomy_id\s*=\s*tr\.term_taxonomy_id\s+INNER\s+JOIN\s+`?([^`\s]+)`?\s+AS\s+t\s+ON\s+t\.term_id\s*=\s*tt\.term_id\s+WHERE\s+tr\.object_id\s*=\s*([0-9]+)\s+AND\s+tt\.taxonomy\s*=\s*\'((?:\\\'|[^\'])*)\'/i',
						$query,
						$term_matches
					)
				) {
					$relationships_table = (string) ($term_matches[1] ?? '');
					$taxonomy_table      = (string) ($term_matches[2] ?? '');
					$terms_table         = (string) ($term_matches[3] ?? '');
					$post_id             = (int) ($term_matches[4] ?? 0);
					$taxonomy            = stripslashes((string) ($term_matches[5] ?? ''));

					if (
						'wp_term_relationships' !== $relationships_table
						|| 'wp_term_taxonomy' !== $taxonomy_table
						|| 'wp_terms' !== $terms_table
					) {
						return [];
					}

					$names = WordPressTestState::$object_terms[$post_id][$taxonomy] ?? [];
					$rows  = array_map(
						static fn(string $name): array => ['term_name' => $name],
						array_values(array_map('strval', is_array($names) ? $names : []))
					);

					return ARRAY_A === $output
						? $rows
						: array_map(static fn(array $row): object => (object) $row, $rows);
				}

				if (! preg_match('/FROM\s+([^\s]+)/i', $query, $matches)) {
					return [];
				}

				$table = (string) ($matches[1] ?? '');

				if ('wp_posts' === trim($table, '`')) {
					if (
						preg_match(
							'/^\s*SELECT\s+ID,\s*post_author,.*\bpost_modified\b.*\bpost_modified_gmt\b.*\s+FROM\b/i',
							$query
						)
					) {
						$post_id = 0;

						if (preg_match('/\bID\s*=\s*([0-9]+)/i', $query, $id_match)) {
							$post_id = (int) ($id_match[1] ?? 0);
						}

						$hook = WordPressTestState::$before_raw_post_row;

						if (is_callable($hook)) {
							WordPressTestState::$before_raw_post_row = null;
							$hook($post_id);
						}

						if (WordPressTestState::$next_raw_post_row_returns_null) {
							WordPressTestState::$next_raw_post_row_returns_null = false;

							return [];
						}
					}

					$rows = array_map(
						static fn(object $post): array => get_object_vars($post),
						array_values(WordPressTestState::$posts)
					);

					if (preg_match('/\bID\s*=\s*([0-9]+)/i', $query, $id_match)) {
						$post_id = (int) ($id_match[1] ?? 0);
						$rows    = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (int) ($row['ID'] ?? 0) === $post_id
							)
						);
					}

					foreach (['post_type', 'post_name', 'post_status'] as $column) {
						if (! preg_match("/\\b{$column}\\s*=\\s*'((?:\\\\'|[^'])*)'/i", $query, $column_match)) {
							continue;
						}

						$expected = stripslashes((string) ($column_match[1] ?? ''));
						$rows     = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (string) ($row[$column] ?? '') === $expected
							)
						);
					}

					return ARRAY_A === $output
						? $rows
						: array_map(static fn(array $row): object => (object) $row, $rows);
				}

				if (str_ends_with(trim($table, '`'), '_postmeta')) {
					$table    = trim($table, '`');
					$post_id  = 0;
					$meta_key = '';

					if (preg_match('/\bpost_id\s*=\s*([0-9]+)/i', $query, $id_match)) {
						$post_id = (int) ($id_match[1] ?? 0);
					}

					if (preg_match("/\\bmeta_key\\s*=\\s*'((?:\\\\'|[^'])*)'/i", $query, $key_match)) {
						$meta_key = stripslashes((string) ($key_match[1] ?? ''));
					}

					$rows = array_values(
						array_filter(
							$this->postmeta_rows($table),
							static fn(array $row): bool => ($post_id <= 0 || (int) ($row['post_id'] ?? 0) === $post_id)
								&& ('' === $meta_key || (string) ($row['meta_key'] ?? '') === $meta_key)
						)
					);

					if (preg_match('/SELECT\s+(.+?)\s+FROM\s+/is', $query, $select_match)) {
						$columns = array_values(
							array_filter(
								array_map(
									static fn(string $column): string => trim(str_replace('`', '', $column)),
									explode(',', (string) ($select_match[1] ?? '*'))
								),
								static fn(string $column): bool => '' !== $column && '*' !== $column
							)
						);

						if ([] !== $columns) {
							$rows = array_map(
								static fn(array $row): array => array_intersect_key($row, array_flip($columns)),
								$rows
							);
						}
					}

					return ARRAY_A === $output
						? $rows
						: array_map(static fn(array $row): object => (object) $row, $rows);
				}

				$rows  = array_values(WordPressTestState::$db_tables[$table] ?? []);
				$all_rows = $rows;
				$has_entity_pairs = false;

				if (preg_match('/\b1\s*=\s*0\b/', $query)) {
					$rows = [];
				}

				if (preg_match("/document_scope_key\s*=\s*'([^']*)'/i", $query, $matches)) {
					$scope_key = stripslashes((string) ($matches[1] ?? ''));
					$rows      = array_values(
						array_filter(
							$rows,
							static fn(array $row): bool => (string) ($row['document_scope_key'] ?? '') === $scope_key
						)
					);
				}

				if (preg_match("/surface\s*=\s*'([^']*)'/i", $query, $matches)) {
					$surface = stripslashes((string) ($matches[1] ?? ''));
					$rows    = array_values(
						array_filter(
							$rows,
							static fn(array $row): bool => (string) ($row['surface'] ?? '') === $surface
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
								static fn(array $match): array => [
									'entity_type' => stripslashes((string) ($match[1] ?? '')),
									'entity_ref'  => stripslashes((string) ($match[2] ?? '')),
								],
								$matches
							),
							static fn(array $pair): bool => '' !== $pair['entity_type'] || '' !== $pair['entity_ref']
						)
					);
					$has_entity_pairs = [] !== $entity_pairs;
					$rows             = array_values(
						array_filter(
							$rows,
							static function (array $row) use ($entity_pairs): bool {
								foreach ($entity_pairs as $pair) {
									if (
										(string) ($row['entity_type'] ?? '') === $pair['entity_type']
										&& (string) ($row['entity_ref'] ?? '') === $pair['entity_ref']
									) {
										return true;
									}
								}

								return false;
							}
						)
					);
				}

				if (! $has_entity_pairs) {
					if (preg_match("/entity_type\s*=\s*'([^']*)'/i", $query, $matches)) {
						$entity_type = stripslashes((string) ($matches[1] ?? ''));
						$rows        = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (string) ($row['entity_type'] ?? '') === $entity_type
							)
						);
					}

					if (preg_match("/surface\s*<>\s*'([^']*)'/i", $query, $matches)) {
						$surface = stripslashes((string) ($matches[1] ?? ''));
						$rows    = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (string) ($row['surface'] ?? '') !== $surface
							)
						);
					}

					if (! preg_match('/\bFROM\s+\S+\s+AS\s+/i', $query)) {
						if (preg_match("/activity_type\s*=\s*'([^']*)'/i", $query, $matches)) {
							$activity_type = stripslashes((string) ($matches[1] ?? ''));
							$rows          = array_values(
								array_filter(
									$rows,
									static fn(array $row): bool => (string) ($row['activity_type'] ?? '') === $activity_type
								)
							);
						}

						if (preg_match("/activity_type\s*<>\s*'([^']*)'/i", $query, $matches)) {
							$activity_type = stripslashes((string) ($matches[1] ?? ''));
							$rows          = array_values(
								array_filter(
									$rows,
									static fn(array $row): bool => (string) ($row['activity_type'] ?? '') !== $activity_type
								)
							);
						}

						if (preg_match("/execution_result\s*=\s*'([^']*)'/i", $query, $matches)) {
							$execution_result = stripslashes((string) ($matches[1] ?? ''));
							$regexp_pattern   = null;

							if (preg_match("/CONVERT\(HEX\(execution_result\)\s+USING\s+utf8mb4\)\s+REGEXP\s*'([^']*)'/i", $query, $regexp_matches)) {
								$regexp_pattern = stripslashes((string) ($regexp_matches[1] ?? ''));
							}

							$rows = array_values(
								array_filter(
									$rows,
									static function (array $row) use ($execution_result, $regexp_pattern): bool {
										$stored = (string) ($row['execution_result'] ?? '');

										if ($stored === $execution_result) {
											return true;
										}

										return is_string($regexp_pattern)
											&& 1 === preg_match(
												'/' . str_replace('/', '\\/', $regexp_pattern) . '/i',
												strtoupper(bin2hex($stored))
											);
									}
								)
							);
						}
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
						if (preg_match("/(?:\\w+\\.)?{$column}\\s*=\\s*'([^']*)'/i", $query, $matches)) {
							$value = stripslashes((string) ($matches[1] ?? ''));
							$rows  = array_values(
								array_filter(
									$rows,
									static fn(array $row): bool => (string) ($row[$column] ?? '') === $value
								)
							);
						}

						if (preg_match("/(?:\\w+\\.)?{$column}\\s*<>\\s*'([^']*)'/i", $query, $matches)) {
							$value = stripslashes((string) ($matches[1] ?? ''));
							$rows  = array_values(
								array_filter(
									$rows,
									static fn(array $row): bool => (string) ($row[$column] ?? '') !== $value
								)
							);
						}
					}

					if (preg_match('/(?:\w+\.)?user_id\s*=\s*(\d+)/i', $query, $matches)) {
						$user_id = (int) ($matches[1] ?? 0);
						$rows    = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (int) ($row['user_id'] ?? 0) === $user_id
							)
						);
					}

					if (preg_match('/(?:\w+\.)?user_id\s*<>\s*(\d+)/i', $query, $matches)) {
						$user_id = (int) ($matches[1] ?? 0);
						$rows    = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (int) ($row['user_id'] ?? 0) !== $user_id
							)
						);
					}

					if (preg_match("/entity_ref\s*=\s*'([^']*)'/i", $query, $matches)) {
						$entity_ref = stripslashes((string) ($matches[1] ?? ''));
						$rows       = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (string) ($row['entity_ref'] ?? '') === $entity_ref
							)
						);
					}
				}

				if (preg_match("/\bactivity_id\b\s*=\s*'([^']*)'/i", $query, $matches)) {
					$activity_id = stripslashes((string) ($matches[1] ?? ''));
					$rows        = array_values(
						array_filter(
							$rows,
							static fn(array $row): bool => (string) ($row['activity_id'] ?? '') === $activity_id
						)
					);
				}

				foreach (['attestation_id', 'reverts_attestation_id', 'supersedes_attestation_id', 'related_activity_id'] as $column) {
					if (preg_match("/\b{$column}\b\s+IN\s*\(([^)]*)\)/i", $query, $matches)) {
						$values = [];

						if (preg_match_all("/'([^']*)'/", (string) ($matches[1] ?? ''), $value_matches)) {
							$values = array_map(
								static fn(string $value): string => stripslashes($value),
								$value_matches[1] ?? []
							);
						}

						$rows = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => in_array((string) ($row[$column] ?? ''), $values, true)
							)
						);
					}

					if (preg_match("/\b{$column}\b\s*=\s*'([^']*)'/i", $query, $matches)) {
						$value = stripslashes((string) ($matches[1] ?? ''));
						$rows  = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => (string) ($row[$column] ?? '') === $value
							)
						);
					}

					if (preg_match("/\b{$column}\b\s+IS\s+NULL/i", $query)) {
						$rows = array_values(
							array_filter(
								$rows,
								static fn(array $row): bool => null === ($row[$column] ?? null)
							)
						);
					}
				}

				if (preg_match_all("/(?:\\w+\\.)?created_at\\s*(>=|<=|<|>)\\s*'([^']+)'/i", $query, $matches, PREG_SET_ORDER)) {
					foreach ($matches as $match) {
						$operator = (string) ($match[1] ?? '>=');
						$value    = stripslashes((string) ($match[2] ?? ''));
						$rows     = array_values(
							array_filter(
								$rows,
								static function (array $row) use ($operator, $value): bool {
									$created_at = (string) ($row['created_at'] ?? '');

									return match ($operator) {
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

				if (preg_match("/LOWER\\(t\\.admin_search_text\\)\\s+LIKE\\s+'([^']*)'/i", $query, $matches)) {
					$needle = strtolower(trim(stripslashes((string) ($matches[1] ?? '')), '%'));
					$rows   = array_values(
						array_filter(
							$rows,
							static function (array $row) use ($needle): bool {
								if ('' === $needle) {
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
									if (str_contains(strtolower((string) ($row[$column] ?? '')), $needle)) {
										return true;
									}
								}

								return false;
							}
						)
					);
				}

				if (preg_match("/\bactivity_id\b\s+IN\s*\\(([^\\)]+)\\)/i", $query, $matches)) {
					$activity_ids = array_values(
						array_filter(
							array_map(
								static fn(string $value): string => trim(stripslashes($value), " \t\n\r\0\x0B'"),
								explode(',', (string) ($matches[1] ?? ''))
							)
						)
					);
					$rows         = array_values(
						array_filter(
							$rows,
							static fn(array $row): bool => in_array(
								(string) ($row['activity_id'] ?? ''),
								$activity_ids,
								true
							)
						)
					);
				}

				if (preg_match("/FIND_IN_SET\\s*\\(\\s*activity_id\\s*,\\s*'([^']*)'\\s*\\)\\s*>\\s*0/i", $query, $matches)) {
					$activity_ids = array_values(
						array_filter(
							array_map(
								static fn(string $value): string => trim(stripslashes($value)),
								explode(',', (string) ($matches[1] ?? ''))
							)
						)
					);
					$rows         = array_values(
						array_filter(
							$rows,
							static fn(array $row): bool => in_array(
								(string) ($row['activity_id'] ?? ''),
								$activity_ids,
								true
							)
						)
					);
				}

				if (preg_match('/COUNT\(\*\)\s+AS\s+total/i', $query) && str_contains($query, 'AS admin_status')) {
					$grouped = [];

					$claim_regexp_is_exact = 1 === preg_match(
						'/CONVERT\(HEX\(t\.execution_result\)\s+USING\s+utf8mb4\)\s+REGEXP/i',
						$query
					);

					foreach ($rows as $row) {
						$status = $this->resolve_activity_admin_status($row, $all_rows, $claim_regexp_is_exact);

						if (! isset($grouped[$status])) {
							$grouped[$status] = 0;
						}

						++$grouped[$status];
					}

					ksort($grouped);

					return array_map(
						static fn(string $status, int $total): array => [
							'admin_status' => $status,
							'total'        => $total,
						],
						array_keys($grouped),
						array_values($grouped)
					);
				}

				if (preg_match('/SELECT\s+(.+?)\s+AS\s+value(?:,\s+(.+?)\s+AS\s+label)?\s+FROM\s+/is', $query, $matches)) {
					$value_column = $this->normalize_select_column((string) ($matches[1] ?? ''));
					$label_column = isset($matches[2]) ? $this->normalize_select_column((string) $matches[2]) : '';
					$grouped      = [];

					foreach ($rows as $row) {
						$value = (string) ($row[$value_column] ?? '');
						$label = '' !== $label_column ? (string) ($row[$label_column] ?? '') : '';

						if ('' === $value || isset($grouped[$value . "\0" . $label])) {
							continue;
						}

						$grouped[$value . "\0" . $label] = [
							'value' => $value,
							'label' => $label,
						];
					}

					usort(
						$grouped,
						static fn(array $left, array $right): int => strnatcasecmp(
							(string) ($left['label'] ?: $left['value']),
							(string) ($right['label'] ?: $right['value'])
						)
					);

					return array_values($grouped);
				}

				$order_column    = 'created_at';
				$order_direction = 'ASC';

				if (preg_match('/ORDER BY\s+(?:\w+\.)?([a-z_]+)\s+(ASC|DESC)/i', $query, $matches)) {
					$order_column    = strtolower((string) ($matches[1] ?? 'created_at'));
					$order_direction = strtoupper((string) ($matches[2] ?? 'ASC'));
				}

				usort(
					$rows,
					static function (array $left, array $right) use ($order_column, $order_direction): int {
						if (in_array($order_column, ['id', 'user_id'], true)) {
							$result = (int) ($left[$order_column] ?? 0) <=> (int) ($right[$order_column] ?? 0);
						} else {
							$left_value  = (string) ($left[$order_column] ?? '');
							$right_value = (string) ($right[$order_column] ?? '');
							$result      = strcmp($left_value, $right_value);
						}

						if (0 === $result) {
							$result = (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
						}

						return 'DESC' === $order_direction ? -1 * $result : $result;
					}
				);

				if (preg_match('/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $query, $matches)) {
					$rows = array_slice(
						$rows,
						(int) ($matches[2] ?? 0),
						(int) ($matches[1] ?? 0)
					);
				}

				if (preg_match('/SELECT\s+(.+?)\s+FROM\s+/is', $query, $matches)) {
					$select_clause = trim((string) ($matches[1] ?? '*'));

					if ('*' !== $select_clause) {
						$columns = array_values(
							array_filter(
								array_map(
									static fn(string $column): string => trim(
										str_replace('`', '', $column)
									),
									explode(',', $select_clause)
								),
								static fn(string $column): bool => '' !== $column
							)
						);
						$rows    = array_map(
							static fn(array $row): array => array_intersect_key(
								$row,
								array_flip($columns)
							),
							$rows
						);
					}
				}

				if (ARRAY_A === $output) {
					return $rows;
				}

				return array_map(
					static fn(array $row): object => (object) $row,
					$rows
				);
			}

			/** @return array<int, array<string, mixed>> */
			private function postmeta_rows(string $table): array
			{
				if ('wp_postmeta' !== $table) {
					return array_values(WordPressTestState::$db_tables[$table] ?? []);
				}

				$rows = [];

				foreach (WordPressTestState::$post_meta as $post_id => $metadata) {
					foreach ($metadata as $meta_key => $meta_value) {
						$rows[] = [
							'meta_id'    => flavor_agent_test_meta_id((int) $post_id, (string) $meta_key),
							'post_id'    => (int) $post_id,
							'meta_key'   => (string) $meta_key,
							'meta_value' => $meta_value,
						];
					}
				}

				return $rows;
			}

			/** @param array<int, array<string, mixed>> $rows */
			private function store_postmeta_rows(string $table, array $rows): void
			{
				if ('wp_postmeta' !== $table) {
					WordPressTestState::$db_tables[$table] = array_values($rows);

					return;
				}

				WordPressTestState::$post_meta = [];

				foreach ($rows as $row) {
					$post_id  = (int) ($row['post_id'] ?? 0);
					$meta_key = (string) ($row['meta_key'] ?? '');

					if ($post_id > 0 && '' !== $meta_key) {
						WordPressTestState::$post_meta[$post_id][$meta_key] = $row['meta_value'] ?? '';
					}
				}
			}

			private function guarded_meta_update_matches(
				string $query,
				int $post_id,
				string $meta_key,
				string $expected_value
			): bool
			{
				$expected_assignment = "`meta_value` = '" . str_replace("'", "\\'", $expected_value) . "'";

				if (
					! preg_match('/^UPDATE\s+`?([^`\s]+)`?/i', $query, $table_match)
					|| $this->postmeta !== (string) ($table_match[1] ?? '')
					|| ! str_contains($query, $expected_assignment)
					|| ! preg_match('/`meta_id`\s*=\s*([0-9]+)/i', $query, $identity_match)
					|| ! preg_match('/`post_id`\s*=\s*([0-9]+)/i', $query, $post_match)
					|| ! preg_match('/HEX\(`meta_key`\)\s*=\s*\'([A-F0-9]*)\'/i', $query, $key_match)
					|| ! preg_match('/`owner`\.`ID`\s*=\s*([0-9]+)/i', $query, $owner_match)
					|| ! preg_match('/HEX\(`owner`\.`post_type`\)\s*=\s*\'([A-F0-9]*)\'/i', $query, $type_match)
					|| ! preg_match('/HEX\(`owner`\.`post_name`\)\s*=\s*\'([A-F0-9]*)\'/i', $query, $name_match)
					|| ! preg_match('/HEX\(`owner`\.`post_status`\)\s*=\s*\'([A-F0-9]*)\'/i', $query, $status_match)
					|| ! preg_match('/HEX\(`owner`\.`post_password`\)\s*=\s*\'([A-F0-9]*)\'/i', $query, $password_match)
					|| ! preg_match('/HEX\(`owner`\.`post_modified`\)\s*=\s*\'([A-F0-9]*)\'/i', $query, $modified_match)
					|| ! preg_match('/HEX\(`owner`\.`post_modified_gmt`\)\s*=\s*\'([A-F0-9]*)\'/i', $query, $modified_gmt_match)
				) {
					return false;
				}

				$current_exists = array_key_exists($meta_key, WordPressTestState::$post_meta[$post_id] ?? []);
				$current_value  = $current_exists ? WordPressTestState::$post_meta[$post_id][$meta_key] : null;
				$post           = WordPressTestState::$posts[$post_id] ?? null;

				if (
					! $current_exists
					|| ! is_object($post)
					|| flavor_agent_test_meta_id($post_id, $meta_key) !== (int) ($identity_match[1] ?? 0)
					|| $post_id !== (int) ($post_match[1] ?? 0)
					|| strtoupper(bin2hex($meta_key)) !== strtoupper((string) ($key_match[1] ?? ''))
					|| $post_id !== (int) ($owner_match[1] ?? 0)
					|| strtoupper(bin2hex((string) ($post->post_type ?? ''))) !== strtoupper((string) ($type_match[1] ?? ''))
					|| strtoupper(bin2hex((string) ($post->post_name ?? ''))) !== strtoupper((string) ($name_match[1] ?? ''))
					|| strtoupper(bin2hex((string) ($post->post_status ?? ''))) !== strtoupper((string) ($status_match[1] ?? ''))
					|| strtoupper(bin2hex((string) ($post->post_password ?? ''))) !== strtoupper((string) ($password_match[1] ?? ''))
					|| strtoupper(bin2hex((string) ($post->post_modified ?? ''))) !== strtoupper((string) ($modified_match[1] ?? ''))
					|| strtoupper(bin2hex((string) ($post->post_modified_gmt ?? ''))) !== strtoupper((string) ($modified_gmt_match[1] ?? ''))
				) {
					return false;
				}

				if (str_contains($query, '`meta_value` IS NULL')) {
					if (null !== $current_value) {
						return false;
					}
				} else {
					$stored_value = is_string($current_value)
						? $current_value
						: maybe_serialize($current_value);

					if (! is_string($stored_value) || ! $this->guarded_digest_matches($query, '`meta_value`', $stored_value)) {
						return false;
					}
				}

				return $this->guarded_digest_matches(
					$query,
					'`owner`\.`post_content`',
					(string) ($post->post_content ?? '')
				);
			}

			private function guarded_digest_matches(string $query, string $column_pattern, string $current): bool
			{
				$pattern = '/OCTET_LENGTH\(' . $column_pattern . '\)\s*=\s*([0-9]+)'
					. '\s+AND\s+MD5\(CONCAT\(\'([a-f0-9]+)\',\s*HEX\(' . $column_pattern . '\),\s*\'([a-f0-9]+)\'\)\)\s*=\s*\'([a-f0-9]{32})\''
					. '\s+AND\s+MD5\(CONCAT\(\'([a-f0-9]+)\',\s*HEX\(' . $column_pattern . '\),\s*\'([a-f0-9]+)\'\)\)\s*=\s*\'([a-f0-9]{32})\'/i';

				if (! preg_match($pattern, $query, $matches)) {
					return false;
				}

				$current_hex = strtoupper(bin2hex($current));

				return strlen($current) === (int) ($matches[1] ?? -1)
					&& hash_equals((string) ($matches[4] ?? ''), md5((string) ($matches[2] ?? '') . $current_hex . (string) ($matches[3] ?? '')))
					&& hash_equals((string) ($matches[7] ?? ''), md5((string) ($matches[5] ?? '') . $current_hex . (string) ($matches[6] ?? '')));
			}

			private function row_matches(array $row, array $where): bool
			{
				foreach ($where as $column => $value) {
					if ((string) ($row[$column] ?? '') !== (string) $value) {
						return false;
					}
				}

				return true;
			}

			private function normalize_select_column(string $column): string
			{
				$column = trim($column);

				if (str_contains($column, '.')) {
					$parts  = explode('.', $column);
					$column = (string) end($parts);
				}

				return trim(str_replace('`', '', $column));
			}

			/**
			 * @param array<string, mixed> $row
			 * @param array<int, array<string, mixed>> $all_rows
			 */
			private function resolve_activity_admin_status(
				array $row,
				array $all_rows,
				bool $claim_regexp_is_exact
			): string
			{
				$undo = json_decode((string) ($row['undo_state'] ?? ''), true);
				$undo_status = is_array($undo) ? (string) ($undo['status'] ?? 'available') : 'available';
				$is_review = 'request_diagnostic' === (string) ($row['activity_type'] ?? '')
					|| 'review' === (string) ($row['execution_result'] ?? '');
				$execution_result = (string) ($row['execution_result'] ?? '');

				if (1 === preg_match('/^claim:[a-f0-9]{24}$/' . ($claim_regexp_is_exact ? '' : 'i'), $execution_result)) {
					return 'pending';
				}

				$non_executed = in_array(
					$execution_result,
					['pending', 'rejected', 'expired', 'failed'],
					true
				);

				if ($non_executed) {
					return (string) $row['execution_result'];
				}

				if ($is_review) {
					return 'failed' === $undo_status ? 'failed' : 'review';
				}

				if ('undone' === $undo_status) {
					return 'undone';
				}

				foreach ($all_rows as $candidate) {
					if (
						(string) ($candidate['entity_type'] ?? '') !== (string) ($row['entity_type'] ?? '')
						|| (string) ($candidate['entity_ref'] ?? '') !== (string) ($row['entity_ref'] ?? '')
						|| (
							'' === (string) ($row['entity_type'] ?? '')
							&& '' === (string) ($row['entity_ref'] ?? '')
						)
					) {
						continue;
					}

					$is_newer = (string) ($candidate['created_at'] ?? '') > (string) ($row['created_at'] ?? '')
						|| (
							(string) ($candidate['created_at'] ?? '') === (string) ($row['created_at'] ?? '')
							&& (int) ($candidate['id'] ?? 0) > (int) ($row['id'] ?? 0)
						);

					if (! $is_newer) {
						continue;
					}

					$candidate_undo = json_decode((string) ($candidate['undo_state'] ?? ''), true);
					$candidate_status = is_array($candidate_undo) ? (string) ($candidate_undo['status'] ?? 'available') : 'available';
					$candidate_review = 'request_diagnostic' === (string) ($candidate['activity_type'] ?? '')
						|| 'review' === (string) ($candidate['execution_result'] ?? '')
						|| in_array(
							(string) ($candidate['execution_result'] ?? ''),
							['pending', 'rejected', 'expired', 'failed'],
							true
						);

					if (! $candidate_review && 'undone' !== $candidate_status) {
						return 'blocked';
					}
				}

				return 'failed' === $undo_status ? 'failed' : 'applied';
			}
		}
	}

	if (! class_exists('WP_Block_Type_Registry')) {
		class WP_Block_Type_Registry
		{

			private static ?self $instance = null;

			/**
			 * @var array<string, object>
			 */
			private array $registered = [];

			public static function get_instance(): self
			{
				if (null === self::$instance) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			public function get_registered(string $block_name): ?object
			{
				return $this->registered[$block_name] ?? null;
			}

			/**
			 * @return array<string, object>
			 */
			public function get_all_registered(): array
			{
				return $this->registered;
			}

			public function register(string $block_name, array $args): void
			{
				$block_type = (object) $args;
				$block_type->name = $block_name;

				if (array_key_exists('allowedBlocks', $args)) {
					$block_type->allowed_blocks = $args['allowedBlocks'];
					unset($block_type->allowedBlocks);
				}

				if (array_key_exists('apiVersion', $args)) {
					$block_type->api_version = $args['apiVersion'];
					unset($block_type->apiVersion);
				}

				$this->registered[$block_name] = $block_type;
			}

			public function reset(): void
			{
				$this->registered = [];
			}
		}
	}

	if (! class_exists('WP_Block_Styles_Registry')) {
		class WP_Block_Styles_Registry
		{

			private static ?self $instance = null;

			/**
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			private array $registered = [];

			public static function get_instance(): self
			{
				if (null === self::$instance) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			/**
			 * @param string|string[] $block_name
			 * @param array<string, mixed> $style_properties
			 */
			public function register($block_name, array $style_properties): bool
			{
				$style_name = $style_properties['name'] ?? '';

				if (! is_string($style_name) || '' === $style_name) {
					return false;
				}

				// Core backfills the label from the name when it is absent.
				if (empty($style_properties['label'])) {
					$style_properties['label'] = $style_name;
				}

				$block_names = is_array($block_name) ? $block_name : [$block_name];

				foreach ($block_names as $name) {
					$this->registered[(string) $name][$style_name] = $style_properties;
				}

				return true;
			}

			/**
			 * @return array<string, array<string, mixed>>
			 */
			public function get_registered_styles_for_block(string $block_name): array
			{
				return $this->registered[$block_name] ?? [];
			}

			/**
			 * @return array<string, array<string, array<string, mixed>>>
			 */
			public function get_all_registered(): array
			{
				return $this->registered;
			}

			public function unregister(string $block_name, string $style_name): bool
			{
				if (! isset($this->registered[$block_name][$style_name])) {
					return false;
				}

				unset($this->registered[$block_name][$style_name]);

				return true;
			}

			public function reset(): void
			{
				$this->registered = [];
			}
		}
	}

	if (! class_exists('WP_Block_Patterns_Registry')) {
		class WP_Block_Patterns_Registry
		{

			private static ?self $instance = null;

			/**
			 * @var array<string, array<string, mixed>>
			 */
			private array $registered = [];

			public static function get_instance(): self
			{
				if (null === self::$instance) {
					self::$instance = new self();
				}

				return self::$instance;
			}

			public function register(string $pattern_name, array $pattern_properties): void
			{
				$this->registered[$pattern_name] = array_merge($pattern_properties, ['name' => $pattern_name]);
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function get_registered(string $pattern_name): ?array
			{
				return $this->registered[$pattern_name] ?? null;
			}

			public function is_registered(string $pattern_name): bool
			{
				return isset($this->registered[$pattern_name]);
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function get_all_registered(): array
			{
				return array_values($this->registered);
			}

			public function reset(): void
			{
				$this->registered = [];
			}
		}
	}

	if (! class_exists('WP_Screen')) {
		class WP_Screen
		{

			/** @var array<int, array<string, mixed>> */
			public array $help_tabs = [];

			public string $help_sidebar = '';

			public function add_help_tab(array $args): void
			{
				$this->help_tabs[] = $args;
			}

			public function set_help_sidebar(string $content): void
			{
				$this->help_sidebar = $content;
			}
		}
	}

	if (! class_exists('WP_Theme')) {
		class WP_Theme
		{

			/**
			 * @param array<string, mixed> $data
			 */
			public function __construct(
				private array $data = []
			) {}

			public function get(string $field)
			{
				return match ($field) {
					'Name'       => $this->data['name'] ?? '',
					'Version'    => $this->data['version'] ?? '',
					'Stylesheet' => $this->data['stylesheet'] ?? '',
					'Template'   => $this->data['template'] ?? '',
					default      => $this->data[$field] ?? '',
				};
			}

			public function get_stylesheet(): string
			{
				return (string) ($this->data['stylesheet'] ?? '');
			}

			public function get_template(): string
			{
				return (string) ($this->data['template'] ?? '');
			}
		}
	}

	if (! function_exists('get_option')) {
		function get_option(string $name, $default = false)
		{
			global $wpdb;

			$table = is_object($wpdb) && isset($wpdb->options) ? (string) $wpdb->options : 'wp_options';

			if ('wp_options' === $table) {
				if ( isset( WordPressTestState::$option_notoptions_cache[ $name ] ) ) {
					return $default;
				}

				if ( array_key_exists( $name, WordPressTestState::$options ) ) {
					return WordPressTestState::$options[ $name ];
				}

				WordPressTestState::$option_notoptions_cache[ $name ] = true;

				return $default;
			}

			foreach (WordPressTestState::$db_tables[$table] ?? [] as $row) {
				if ($name !== (string) ($row['option_name'] ?? '')) {
					continue;
				}

				$value = $row['option_value'] ?? $default;

				if (is_string($value)) {
					$unserialized = @unserialize($value);

					if (false !== $unserialized || 'b:0;' === $value) {
						return $unserialized;
					}
				}

				return $value;
			}

			return $default;
		}
	}

	if (! function_exists('get_stylesheet')) {
		function get_stylesheet(): string
		{
			$stylesheet = WordPressTestState::$active_theme['stylesheet'] ?? '';

			return '' !== $stylesheet ? (string) $stylesheet : 'test-theme';
		}
	}

	if (! function_exists('post_type_exists')) {
		function post_type_exists(string $post_type): bool
		{
			return array_key_exists($post_type, WordPressTestState::$registered_post_types);
		}
	}

	if (! function_exists('taxonomy_exists')) {
		function taxonomy_exists(string $taxonomy): bool
		{
			return array_key_exists($taxonomy, WordPressTestState::$registered_taxonomies);
		}
	}

	if (! function_exists('wp_get_connectors')) {
		function wp_get_connectors(): array
		{
			$error_message = WordPressTestState::get_connector_api_error(__FUNCTION__);
			if (null !== $error_message) {
				throw new \RuntimeException(esc_html(sanitize_text_field($error_message)));
			}

			return WordPressTestState::$connectors;
		}
	}

	if (! function_exists('wp_parse_args')) {
		function wp_parse_args($args, array $defaults = []): array
		{
			if (is_object($args)) {
				$args = get_object_vars($args);
			}

			if (! is_array($args)) {
				$args = [];
			}

			return array_merge($defaults, $args);
		}
	}

	if (! function_exists('add_query_arg')) {
		function add_query_arg(array $args, string $url): string
		{
			$parts = wp_parse_url($url);

			if (! is_array($parts)) {
				$parts = [];
			}

			$query_args = [];
			if (isset($parts['query']) && is_string($parts['query'])) {
				parse_str($parts['query'], $query_args);
			}

			foreach ($args as $key => $value) {
				if (null === $value) {
					unset($query_args[$key]);
					continue;
				}

				$query_args[$key] = $value;
			}

			$scheme   = isset($parts['scheme']) ? (string) $parts['scheme'] . '://' : '';
			$host     = isset($parts['host']) ? (string) $parts['host'] : '';
			$port     = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
			$user     = isset($parts['user']) ? (string) $parts['user'] : '';
			$pass     = isset($parts['pass']) ? ':' . (string) $parts['pass'] : '';
			$auth     = '' !== $user ? $user . $pass . '@' : '';
			$path     = isset($parts['path']) ? (string) $parts['path'] : '';
			$query    = http_build_query($query_args, '', '&', PHP_QUERY_RFC3986);
			$fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';

			return $scheme . $auth . $host . $port . $path . ('' !== $query ? '?' . $query : '') . $fragment;
		}
	}

	if (! function_exists('wp_parse_url')) {
		function wp_parse_url(string $url, int $component = -1)
		{
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This test bootstrap provides the WordPress compatibility wrapper when core is unavailable.
			return parse_url($url, $component);
		}
	}

	if (! function_exists('wp_hash')) {
		function wp_hash(string $data, string $scheme = 'auth'): string
		{
			return hash('sha256', $data . '|' . $scheme);
		}
	}

	if (! function_exists('home_url')) {
		function home_url(string $path = '', ?string $scheme = null): string
		{
			$base = WordPressTestState::$home_url;

			if ($path === '') {
				return $base;
			}

			return rtrim($base, '/') . '/' . ltrim($path, '/');
		}
	}

	if (! function_exists('plugin_dir_path')) {
		function plugin_dir_path(string $file): string
		{
			return dirname($file) . '/';
		}
	}

	if (! function_exists('plugin_dir_url')) {
		function plugin_dir_url(string $file): string
		{
			unset($file);

			return 'https://example.test/wp-content/plugins/flavor-agent/';
		}
	}

	if (! function_exists('register_activation_hook')) {
		function register_activation_hook(string $file, $callback): void
		{
			if (is_callable($callback)) {
				WordPressTestState::$activation_hooks[$file] = $callback;
			}
		}
	}

	if (! function_exists('register_deactivation_hook')) {
		function register_deactivation_hook(string $file, $callback): void
		{
			if (is_callable($callback)) {
				WordPressTestState::$deactivation_hooks[$file] = $callback;
			}
		}
	}

	if (! function_exists('untrailingslashit')) {
		function untrailingslashit(string $value): string
		{
			return rtrim($value, '/');
		}
	}

	if (! function_exists('get_current_blog_id')) {
		function get_current_blog_id(): int
		{
			return WordPressTestState::$current_blog_id;
		}
	}

	if (! function_exists('wp_get_environment_type')) {
		function wp_get_environment_type(): string
		{
			return 'tests';
		}
	}

	if (! function_exists('is_admin')) {
		function is_admin(): bool
		{
			return false;
		}
	}

	if (! function_exists('wp_doing_cron')) {
		function wp_doing_cron(): bool
		{
			return false;
		}
	}

	if (! function_exists('get_transient')) {
		function get_transient(string $name)
		{
			return array_key_exists($name, WordPressTestState::$transients)
				? WordPressTestState::$transients[$name]
				: false;
		}
	}

	if (! function_exists('set_transient')) {
		function set_transient(string $name, $value, int $expiration = 0): bool
		{
			WordPressTestState::$transients[$name]            = $value;
			WordPressTestState::$transient_expirations[$name] = $expiration;

			return true;
		}
	}

	if (! function_exists('delete_transient')) {
		function delete_transient(string $name): bool
		{
			unset(WordPressTestState::$transients[$name]);
			unset(WordPressTestState::$transient_expirations[$name]);

			return true;
		}
	}

	if (! function_exists('current_user_can')) {
		function current_user_can(string $capability, ...$args): bool
		{
			WordPressTestState::$capability_checks[] = [
				'capability' => $capability,
				'args'       => $args,
			];

			if ([] !== $args) {
				$specific_key = $capability . ':' . implode(
					':',
					array_map(
						static fn($arg): string => is_scalar($arg) || null === $arg
							? (string) $arg
							: wp_json_encode($arg),
						$args
					)
				);

				if (array_key_exists($specific_key, WordPressTestState::$capabilities)) {
					return (bool) WordPressTestState::$capabilities[$specific_key];
				}
			}

			if (is_callable(WordPressTestState::$capabilities[$capability] ?? null)) {
				return (bool) call_user_func(
					WordPressTestState::$capabilities[$capability],
					...$args
				);
			}

			return (bool) (WordPressTestState::$capabilities[$capability] ?? false);
		}
	}

	if (! function_exists('get_current_user_id')) {
		function get_current_user_id(): int
		{
			return WordPressTestState::$current_user_id;
		}
	}

	if (! function_exists('get_userdata')) {
		function get_userdata(int $user_id)
		{
			$user = WordPressTestState::$users[$user_id] ?? null;

			if (! is_array($user)) {
				return false;
			}

			return (object) array_merge(
				[
					'ID'           => $user_id,
					'display_name' => '',
					'user_login'   => '',
					'roles'        => [],
				],
				$user
			);
		}
	}

	if (! function_exists('__')) {
		function __(string $text, string $domain = 'default'): string
		{
			return $text;
		}
	}

	if (! function_exists('esc_html')) {
		function esc_html(string $text): string
		{
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (! function_exists('esc_attr')) {
		function esc_attr(string $text): string
		{
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (! function_exists('esc_url')) {
		function esc_url(string $url): string
		{
			$url    = trim($url);
			$scheme = parse_url($url, PHP_URL_SCHEME);

			if (
				is_string($scheme) &&
				! in_array(strtolower($scheme), ['http', 'https', 'ftp', 'ftps', 'mailto'], true)
			) {
				return '';
			}

			return esc_attr($url);
		}
	}

	if (! function_exists('esc_textarea')) {
		function esc_textarea(string $text): string
		{
			return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		}
	}

	if (! function_exists('esc_html__')) {
		function esc_html__(string $text, string $domain = 'default'): string
		{
			unset($domain);

			return esc_html($text);
		}
	}

	if (! function_exists('esc_attr__')) {
		function esc_attr__(string $text, string $domain = 'default'): string
		{
			unset($domain);

			return esc_attr($text);
		}
	}

	if (! function_exists('selected')) {
		function selected($selected, $current = true, bool $display = true): string
		{
			$result = '';

			if ((string) $selected === (string) $current) {
				$result = 'selected="selected"';
			}

			if ($display && '' !== $result) {
				echo 'selected="selected"';
			}

			return $result;
		}
	}

	if (! function_exists('checked')) {
		function checked($checked, $current = true, bool $display = true): string
		{
			$result = '';

			if ((string) $checked === (string) $current) {
				$result = 'checked="checked"';
			}

			if ($display && '' !== $result) {
				echo 'checked="checked"';
			}

			return $result;
		}
	}

	if (! function_exists('__return_true')) {
		function __return_true(): bool
		{
			return true;
		}
	}

	if (! function_exists('__return_false')) {
		function __return_false(): bool
		{
			return false;
		}
	}

	if (! function_exists('add_filter')) {
		function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
		{
			if (! isset(WordPressTestState::$filters[$hook_name])) {
				WordPressTestState::$filters[$hook_name] = [];
			}

			if (! isset(WordPressTestState::$filters[$hook_name][$priority])) {
				WordPressTestState::$filters[$hook_name][$priority] = [];
			}

			WordPressTestState::$filters[$hook_name][$priority][] = [
				'callback'      => $callback,
				'accepted_args' => max(0, $accepted_args),
			];

			return true;
		}
	}

	if (! function_exists('add_action')) {
		function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
		{
			return add_filter($hook_name, $callback, $priority, $accepted_args);
		}
	}

	if (! function_exists('do_action')) {
		function do_action(string $hook_name, ...$args): void
		{
			WordPressTestState::$do_action_counts[$hook_name] =
				(WordPressTestState::$do_action_counts[$hook_name] ?? 0) + 1;

			// Pushed before the no-callbacks bail so `doing_action()` answers
			// true for a hook with no listeners, as core does. The Agents API
			// stubs gate registration on exactly that.
			WordPressTestState::$current_actions[] = $hook_name;

			try {
				if (empty(WordPressTestState::$filters[$hook_name])) {
					return;
				}

				$callbacks = WordPressTestState::$filters[$hook_name];
				ksort($callbacks);

				foreach ($callbacks as $entries) {
					foreach ($entries as $entry) {
						$accepted_args = (int) ($entry['accepted_args'] ?? 1);
						$callback_args = 0 === $accepted_args
							? []
							: array_slice($args, 0, $accepted_args);
						call_user_func_array($entry['callback'], $callback_args);
					}
				}
			} finally {
				array_pop(WordPressTestState::$current_actions);
			}
		}
	}

	if (! function_exists('apply_filters')) {
		function apply_filters(string $hook_name, $value, ...$args)
		{
			if (empty(WordPressTestState::$filters[$hook_name])) {
				return $value;
			}

			$callbacks = WordPressTestState::$filters[$hook_name];
			ksort($callbacks);

			foreach ($callbacks as $entries) {
				foreach ($entries as $entry) {
					$accepted_args = (int) ($entry['accepted_args'] ?? 1);
					$callback_args = 0 === $accepted_args
						? []
						: array_slice(array_merge([$value], $args), 0, $accepted_args);
					$value         = call_user_func_array($entry['callback'], $callback_args);
				}
			}

			return $value;
		}
	}

	if (! function_exists('remove_filter')) {
		function remove_filter(string $hook_name, callable $callback, int $priority = 10): bool
		{
			$entries = WordPressTestState::$filters[$hook_name][$priority] ?? null;

			if (! is_array($entries)) {
				return false;
			}

			foreach ($entries as $index => $entry) {
				if (($entry['callback'] ?? null) !== $callback) {
					continue;
				}

				unset(WordPressTestState::$filters[$hook_name][$priority][$index]);

				if ([] === WordPressTestState::$filters[$hook_name][$priority]) {
					unset(WordPressTestState::$filters[$hook_name][$priority]);
				}

				if ([] === WordPressTestState::$filters[$hook_name]) {
					unset(WordPressTestState::$filters[$hook_name]);
				}

				return true;
			}

			return false;
		}
	}

	if (! function_exists('remove_action')) {
		function remove_action(string $hook_name, callable $callback, int $priority = 10): bool
		{
			return remove_filter($hook_name, $callback, $priority);
		}
	}

	if (! function_exists('has_action')) {
		function has_action(string $hook_name, $callback = false)
		{
			if (empty(WordPressTestState::$filters[$hook_name])) {
				return false;
			}

			if (false === $callback) {
				return true;
			}

			foreach (WordPressTestState::$filters[$hook_name] as $priority => $entries) {
				foreach ($entries as $entry) {
					if (($entry['callback'] ?? null) === $callback) {
						return $priority;
					}
				}
			}

			return false;
		}
	}

	if (! function_exists('is_wp_error')) {
		function is_wp_error($value): bool
		{
			return $value instanceof WP_Error;
		}
	}

	if (! function_exists('sanitize_key')) {
		function sanitize_key(string $key): string
		{
			$key = strtolower($key);

			return preg_replace('/[^a-z0-9_-]/', '', $key) ?? '';
		}
	}

	if (! function_exists('sanitize_title')) {
		function sanitize_title(string $title): string
		{
			$title = strtolower(sanitize_text_field($title));
			$title = preg_replace('/[^a-z0-9_\s-]/', '', $title) ?? '';
			$title = preg_replace('/[\s-]+/', '-', $title) ?? '';

			return trim($title, '-');
		}
	}

	if (! function_exists('sanitize_text_field')) {
		function sanitize_text_field($value): string
		{
			$filtered = trim(
				preg_replace(
					'/\s+/u',
					' ',
					wp_strip_all_tags((string) $value)
				) ?? ''
			);

			return (string) apply_filters('sanitize_text_field', $filtered, $value);
		}
	}

	if (! function_exists('absint')) {
		function absint($maybeint): int
		{
			return abs((int) $maybeint);
		}
	}

	if (! function_exists('sanitize_url')) {
		function sanitize_url($url, array $protocols = []): string
		{
			return filter_var((string) $url, FILTER_SANITIZE_URL) ?: '';
		}
	}

	if (! function_exists('sanitize_textarea_field')) {
		function sanitize_textarea_field($value): string
		{
			$filtered = trim(
				preg_replace(
					'/[^\S\r\n]+/u',
					' ',
					wp_strip_all_tags((string) $value)
				) ?? ''
			);

			return (string) apply_filters('sanitize_textarea_field', $filtered, $value);
		}
	}

	if (! function_exists('rest_sanitize_boolean')) {
		function rest_sanitize_boolean($value): bool
		{
			return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
		}
	}

	if (! function_exists('sanitize_html_class')) {
		function sanitize_html_class(string $class, string $fallback = ''): string
		{
			$sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';

			if ('' === $sanitized) {
				return $fallback;
			}

			return $sanitized;
		}
	}

	if (! function_exists('wp_kses_post')) {
		function wp_kses_post(string $content): string
		{
			return $content;
		}
	}

	if (! function_exists('admin_url')) {
		function admin_url(string $path = ''): string
		{
			$normalized = ltrim($path, '/');

			return 'https://example.test/wp-admin/' . $normalized;
		}
	}

	if (! function_exists('add_submenu_page')) {
		function add_submenu_page(
			string $parent_slug,
			string $page_title,
			string $menu_title,
			string $capability,
			string $menu_slug,
			$callback = ''
		): string {
			unset($parent_slug, $page_title, $menu_title, $capability, $callback);

			return 'settings_page_' . $menu_slug;
		}
	}

	if (! function_exists('rest_url')) {
		function rest_url(string $path = ''): string
		{
			$normalized = ltrim($path, '/');

			return 'https://example.test/wp-json/' . $normalized;
		}
	}

	if (! function_exists('wp_create_nonce')) {
		function wp_create_nonce(string $action = '-1'): string
		{
			return 'nonce-' . $action;
		}
	}

	if (! function_exists('wp_get_theme')) {
		function wp_get_theme(?string $stylesheet = null, ?string $theme_root = null)
		{
			unset($stylesheet, $theme_root);

			return new \WP_Theme(WordPressTestState::$active_theme);
		}
	}

	if (! function_exists('get_current_screen')) {
		function get_current_screen()
		{
			return WordPressTestState::$current_screen;
		}
	}

	if (! function_exists('wp_get_global_settings')) {
		function wp_get_global_settings(): array
		{
			return WordPressTestState::$global_settings;
		}
	}

	if (! function_exists('wp_get_global_styles')) {
		function wp_get_global_styles(): array
		{
			return WordPressTestState::$global_styles;
		}
	}

	if (! function_exists('get_block_templates')) {
		function get_block_templates(array $query = [], string $template_type = 'wp_template'): array
		{
			$before_hook = WordPressTestState::$before_block_templates_query;

			if (is_callable($before_hook)) {
				$before_hook($query, $template_type);
			}

			$templates = WordPressTestState::$block_templates[$template_type] ?? [];
			$result    = [];

			foreach ($templates as $template) {
				$template = is_object($template) ? clone $template : (object) $template;
				$wp_id    = (int) ($template->wp_id ?? 0);

				if ($wp_id > 0 && isset(WordPressTestState::$posts[$wp_id])) {
					$template->content = (string) (WordPressTestState::$posts[$wp_id]->post_content ?? '');
				}

				$transform = WordPressTestState::$block_template_content_transform;

				if (is_callable($transform)) {
					$template->content = $transform((string) ($template->content ?? ''), $template_type, (string) ($template->id ?? ''));
				}

				if (isset($query['area']) && (string) ($template->area ?? '') !== (string) $query['area']) {
					continue;
				}

				if (
					! empty($query['slug__in']) &&
					! in_array((string) ($template->slug ?? ''), (array) $query['slug__in'], true)
				) {
					continue;
				}

				if (isset($query['wp_id']) && (int) ($template->wp_id ?? 0) !== (int) $query['wp_id']) {
					continue;
				}

				$result[] = $template;
			}

			$hook = WordPressTestState::$block_templates_read_hook;

			if (is_callable($hook)) {
				WordPressTestState::$block_templates_read_hook = null;
				$hook();
			}

			return $result;
		}
	}

	if (! function_exists('get_block_template')) {
		function get_block_template(string $id, string $template_type = 'wp_template')
		{
			if (WordPressTestState::$next_get_block_template_returns_null) {
				WordPressTestState::$next_get_block_template_returns_null = false;

				return null;
			}

			$parts = explode('//', $id, 2);

			if (2 === count($parts)) {
				[$theme, $slug] = $parts;

				$candidate_ids = array_keys(WordPressTestState::$posts);
				rsort($candidate_ids, SORT_NUMERIC);

				foreach ($candidate_ids as $post_id) {
					$post   = WordPressTestState::$posts[$post_id];
					$terms  = WordPressTestState::$object_terms[(int) $post_id] ?? [];
					$themes = is_array($terms['wp_theme'] ?? null) ? $terms['wp_theme'] : [];

					if (
						! is_object($post)
						|| $template_type !== (string) ($post->post_type ?? '')
						|| $slug !== (string) ($post->post_name ?? '')
						|| ! in_array($theme, $themes, true)
					) {
						continue;
					}

					$areas = is_array($terms['wp_template_part_area'] ?? null)
						? $terms['wp_template_part_area']
						: [];

					$template = (object) [
						'id'      => $id,
						'wp_id'   => (int) $post_id,
						'slug'    => $slug,
						'title'   => (string) ($post->post_title ?? ''),
						'area'    => (string) ($areas[0] ?? ''),
						'content' => (string) ($post->post_content ?? ''),
					];
					$transform = WordPressTestState::$block_template_content_transform;

					if (is_callable($transform)) {
						$template->content = $transform((string) $template->content, $template_type, $id);
					}

					return $template;
				}
			}

			foreach (get_block_templates([], $template_type) as $template) {
				if ((string) ($template->id ?? '') === $id) {
					return $template;
				}
			}

			return null;
		}
	}

	if (! function_exists('inject_ignored_hooked_blocks_metadata_attributes')) {
		function inject_ignored_hooked_blocks_metadata_attributes(object $changes)
		{
			$preparer = WordPressTestState::$block_template_write_preparer;

			return is_callable($preparer) ? $preparer($changes) : $changes;
		}
	}

	if (! function_exists('_filter_block_template_part_area')) {
		function _filter_block_template_part_area($area): string
		{
			$allowed = array_map(
				static fn(array $definition): string => (string) ($definition['area'] ?? ''),
				apply_filters(
					'default_wp_template_part_areas',
					[
						['area' => 'uncategorized'],
						['area' => 'header'],
						['area' => 'footer'],
						['area' => 'navigation-overlay'],
					]
				)
			);

			return in_array((string) $area, $allowed, true)
				? (string) $area
				: WP_TEMPLATE_PART_AREA_UNCATEGORIZED;
		}
	}

	if (! function_exists('get_posts')) {
		function get_posts(array $args = []): array
		{
			WordPressTestState::$get_posts_calls[] = $args;

			$posts     = array_values(WordPressTestState::$posts);
			$post_type = isset($args['post_type']) ? (string) $args['post_type'] : '';
			$post_status = isset($args['post_status']) ? $args['post_status'] : 'publish';
			$search      = isset($args['s']) ? sanitize_text_field((string) $args['s']) : '';
			$orderby     = isset($args['orderby']) ? sanitize_key((string) $args['orderby']) : 'date';
			$order       = strtoupper((string) ($args['order'] ?? 'DESC'));

			if ('' !== $post_type) {
				$posts = array_values(
					array_filter(
						$posts,
						static fn(object $post): bool => (string) ($post->post_type ?? '') === $post_type
					)
				);
			}

			if ('any' !== $post_status) {
				$allowed_statuses = is_array($post_status) ? $post_status : [$post_status];
				$posts            = array_values(
					array_filter(
						$posts,
						static fn(object $post): bool => in_array(
							(string) ($post->post_status ?? ''),
							array_map('strval', $allowed_statuses),
							true
						)
					)
				);
			}

			if (isset($args['author'])) {
				$author_id = (int) $args['author'];
				$posts     = array_values(
					array_filter(
						$posts,
						static fn(object $post): bool => (int) ($post->post_author ?? 0) === $author_id
					)
				);
			}

			if (! empty($args['post__not_in']) && is_array($args['post__not_in'])) {
				$excluded = array_map('intval', $args['post__not_in']);
				$posts    = array_values(
					array_filter(
						$posts,
						static fn(object $post): bool => ! in_array((int) ($post->ID ?? 0), $excluded, true)
					)
				);
			}

			if (isset($args['has_password']) && false === $args['has_password']) {
				$posts = array_values(
					array_filter(
						$posts,
						static fn(object $post): bool => '' === (string) ($post->post_password ?? '')
					)
				);
			}

			if ('' !== $search) {
				$search = strtolower($search);
				$posts  = array_values(
					array_filter(
						$posts,
						static function (object $post) use ($search): bool {
							$haystacks = [
								strtolower((string) ($post->post_title ?? '')),
								strtolower((string) ($post->post_name ?? '')),
							];

							foreach ($haystacks as $haystack) {
								if (str_contains($haystack, $search)) {
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
				static function (object $left, object $right) use ($orderby, $order): int {
					$comparison = match ($orderby) {
						'title' => strcasecmp(
							(string) ($left->post_title ?? ''),
							(string) ($right->post_title ?? '')
						),
						'id' => (int) ($left->ID ?? 0) <=> (int) ($right->ID ?? 0),
						default => strcmp(
							(string) ($left->post_date_gmt ?? ''),
							(string) ($right->post_date_gmt ?? '')
						),
					};

					return 'ASC' === $order ? $comparison : -1 * $comparison;
				}
			);

			$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
			$limit  = isset($args['posts_per_page']) && (int) $args['posts_per_page'] >= 0
				? (int) $args['posts_per_page']
				: null;

			if ($offset > 0 || null !== $limit) {
				$posts = array_slice($posts, $offset, $limit);
			}

			return $posts;
		}
	}

	if (! function_exists('get_post_meta')) {
		function get_post_meta(int $post_id, string $key = '', bool $single = false)
		{
			$filtered = apply_filters('get_post_metadata', null, $post_id, $key, $single, 'post');

			if (null !== $filtered) {
				return $filtered;
			}

			$meta = WordPressTestState::$post_meta[$post_id] ?? [];

			if ('' === $key) {
				return $meta;
			}

			if (! array_key_exists($key, $meta)) {
				return $single ? '' : [];
			}

			$value = $meta[$key];

			if ($single) {
				return $value;
			}

			return is_array($value) ? $value : [$value];
		}
	}

	if (! function_exists('flavor_agent_test_meta_id')) {
		function flavor_agent_test_meta_id(int $post_id, string $meta_key): int
		{
			return ($post_id * 1000) + ((int) sprintf('%u', crc32($meta_key)) % 997) + 1;
		}
	}

	if (! function_exists('is_serialized')) {
		function is_serialized($data, bool $strict = true): bool
		{
			if (! is_string($data)) {
				return false;
			}

			$data = trim($data);

			if ('N;' === $data) {
				return true;
			}

			if (strlen($data) < 4 || ':' !== $data[1]) {
				return false;
			}

			$last = substr($data, -1);

			if ($strict && ';' !== $last && '}' !== $last) {
				return false;
			}

			if (! $strict && ';' !== $last && '}' !== $last && ! str_contains($data, ';')) {
				return false;
			}

			switch ($data[0]) {
				case 's':
					if ($strict && '"' !== substr($data, -2, 1)) {
						return false;
					}
					// Fall through.
				case 'a':
				case 'O':
				case 'E':
					return (bool) preg_match("/^{$data[0]}:[0-9]+:/s", $data);
				case 'b':
				case 'i':
				case 'd':
					return (bool) preg_match("/^{$data[0]}:[0-9.E+-]+;$/", $data);
			}

			return false;
		}
	}

	if (! function_exists('maybe_serialize')) {
		function maybe_serialize($data)
		{
			if (is_array($data) || is_object($data)) {
				return serialize($data);
			}

			if (is_serialized($data, false)) {
				return serialize($data);
			}

			return $data;
		}
	}

	if (! function_exists('metadata_exists')) {
		function metadata_exists(string $meta_type, int $object_id, string $meta_key): bool
		{
			if ('post' !== $meta_type) {
				return false;
			}

			$filtered = apply_filters('get_post_metadata', null, $object_id, $meta_key, true, 'post');

			return null !== $filtered
				|| array_key_exists($meta_key, WordPressTestState::$post_meta[$object_id] ?? []);
		}
	}

	if (! function_exists('wp_get_object_terms')) {
		function wp_get_object_terms($object_ids, $taxonomies, array $args = [])
		{
			$post_id  = (int) (is_array($object_ids) ? ($object_ids[0] ?? 0) : $object_ids);
			$taxonomy = (string) (is_array($taxonomies) ? ($taxonomies[0] ?? '') : $taxonomies);
			$values   = WordPressTestState::$object_terms[$post_id][$taxonomy] ?? [];

			$terms = array_values(array_map('strval', is_array($values) ? $values : []));

			return apply_filters('get_object_terms', $terms, [$post_id], [$taxonomy], $args);
		}
	}

	if (! function_exists('add_metadata')) {
		function add_metadata(string $meta_type, int $object_id, string $meta_key, $meta_value, bool $unique = false)
		{
			if ('post' !== $meta_type || $object_id <= 0) {
				return false;
			}

			$table      = (string) $GLOBALS['wpdb']->postmeta;
			$meta_key   = wp_unslash($meta_key);
			$meta_value = wp_unslash($meta_value);
			$check      = apply_filters("add_{$meta_type}_metadata", null, $object_id, $meta_key, $meta_value, $unique);

			if (null !== $check) {
				return $check;
			}

			if ($unique && array_key_exists($meta_key, WordPressTestState::$post_meta[$object_id] ?? [])) {
				return false;
			}

			$serialized_meta_value = maybe_serialize($meta_value);

			do_action("add_{$meta_type}_meta", $object_id, $meta_key, $meta_value);
			do_action('add_postmeta', $object_id, $meta_key, $meta_value);
			$inserted = $GLOBALS['wpdb']->insert(
				$table,
				[
					'post_id'    => $object_id,
					'meta_key'   => $meta_key,
					'meta_value' => $serialized_meta_value,
				]
			);

			if (! $inserted) {
				return false;
			}

			$meta_id = flavor_agent_test_meta_id($object_id, $meta_key);
			do_action("added_{$meta_type}_meta", $meta_id, $object_id, $meta_key, $meta_value);
			do_action('added_postmeta', $meta_id, $object_id, $meta_key, $meta_value);

			return $meta_id;
		}
	}

	if (! function_exists('update_metadata')) {
		function update_metadata(string $meta_type, int $object_id, string $meta_key, $meta_value, $prev_value = '')
		{
			if ('post' !== $meta_type || $object_id <= 0) {
				return false;
			}

			$table        = (string) $GLOBALS['wpdb']->postmeta;
			$raw_meta_key = $meta_key;
			$passed_value = $meta_value;
			$meta_key     = wp_unslash($meta_key);
			$meta_value   = wp_unslash($meta_value);
			$check        = apply_filters("update_{$meta_type}_metadata", null, $object_id, $meta_key, $meta_value, $prev_value);

			if (null !== $check) {
				return (bool) $check;
			}

			$meta_rows = $GLOBALS['wpdb']->get_results(
				$GLOBALS['wpdb']->prepare(
					'SELECT meta_id, meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC',
					$table,
					$object_id,
					$meta_key
				),
				ARRAY_A
			);
			$current_exists = [] !== $meta_rows;
			$current_value  = $current_exists ? ($meta_rows[0]['meta_value'] ?? null) : null;
			$serialized_meta_value = maybe_serialize($meta_value);

			if (empty($prev_value) && $current_exists && $current_value === $serialized_meta_value) {
				return false;
			}

			if (! $current_exists) {
				return add_metadata($meta_type, $object_id, $raw_meta_key, $passed_value);
			}

			$meta_id = (int) ($meta_rows[0]['meta_id'] ?? flavor_agent_test_meta_id($object_id, $meta_key));
			do_action("update_{$meta_type}_meta", $meta_id, $object_id, $meta_key, $meta_value);
			do_action('update_postmeta', $meta_id, $object_id, $meta_key, $meta_value);
			$where = [
				'post_id'  => $object_id,
				'meta_key' => $meta_key,
			];

			if (! empty($prev_value)) {
				$where['meta_value'] = maybe_serialize($prev_value);
			}

			$updated = $GLOBALS['wpdb']->update(
				$table,
				['meta_value' => $serialized_meta_value],
				$where
			);

			if (! $updated) {
				return false;
			}

			do_action("updated_{$meta_type}_meta", $meta_id, $object_id, $meta_key, $meta_value);
			do_action('updated_postmeta', $meta_id, $object_id, $meta_key, $meta_value);

			return true;
		}
	}

	if (! function_exists('update_post_meta')) {
		function update_post_meta(int $post_id, string $meta_key, $meta_value, $prev_value = '')
		{
			return update_metadata('post', $post_id, $meta_key, $meta_value, $prev_value);
		}
	}

	if (! function_exists('add_post_meta')) {
		function add_post_meta(int $post_id, string $meta_key, $meta_value, bool $unique = false)
		{
			return add_metadata('post', $post_id, $meta_key, $meta_value, $unique);
		}
	}

	if (! function_exists('wp_json_encode')) {
		function wp_json_encode($value, int $flags = 0, int $depth = 512)
		{
			return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $depth);
		}
	}

	if (! function_exists('did_action')) {
		function did_action(string $hook_name): int
		{
			return (int) (WordPressTestState::$do_action_counts[$hook_name] ?? 0);
		}
	}

	if (! function_exists('doing_action')) {
		function doing_action(?string $hook_name = null): bool
		{
			if (null === $hook_name) {
				return [] !== WordPressTestState::$current_actions;
			}

			return in_array($hook_name, WordPressTestState::$current_actions, true);
		}
	}

	if (! function_exists('get_file_data')) {
		function get_file_data(string $file, array $headers, string $context = ''): array
		{
			unset($context);

			$contents = is_readable($file) ? (string) file_get_contents($file) : '';
			$data     = [];

			foreach ($headers as $field => $regex) {
				$data[$field] = preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $contents, $match) && $match[1]
					? trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]) ?? '')
					: '';
			}

			return $data;
		}
	}

	if (! function_exists('wp_register_ability')) {
		function wp_register_ability(string $id, array $args): void
		{
			WordPressTestState::$raw_registered_abilities[$id] = $args;
			$ability_args                                      = $args;

			$ability_class = $ability_args['ability_class'] ?? null;
			if (is_string($ability_class) && class_exists($ability_class)) {
				$ability = new $ability_class($id, $ability_args);

				foreach (['input_schema', 'output_schema', 'meta', 'category'] as $method_name) {
					if (is_callable([$ability, $method_name])) {
						$ability_args[$method_name] = $ability->{$method_name}();
					}
				}

				if (is_callable([$ability, 'execute_callback'])) {
					$ability_args['execute_callback'] = [$ability, 'execute_callback'];
				}

				if (is_callable([$ability, 'permission_callback'])) {
					$ability_args['permission_callback'] = [$ability, 'permission_callback'];
				}
			}

			WordPressTestState::$registered_abilities[$id] = $ability_args;
		}
	}

	if (! function_exists('wp_get_ability')) {
		function wp_get_ability(string $id): ?object
		{
			$args = WordPressTestState::$registered_abilities[$id] ?? null;

			if (! is_array($args)) {
				return null;
			}

			return new class ($id, $args) {
				public function __construct(private string $id, private array $args)
				{
				}

				public function get_name(): string
				{
					return $this->id;
				}

				public function get_description(): string
				{
					return (string) ($this->args['description'] ?? '');
				}

				public function get_input_schema(): array
				{
					return is_array($this->args['input_schema'] ?? null) ? $this->args['input_schema'] : [];
				}

				public function get_meta(): array
				{
					return is_array($this->args['meta'] ?? null) ? $this->args['meta'] : [];
				}

				public function get_meta_item(string $key, mixed $default = null): mixed
				{
					$meta = is_array($this->args['meta'] ?? null) ? $this->args['meta'] : [];

					return $meta[$key] ?? $default;
				}
			};
		}
	}

	if (! function_exists('wp_register_ability_category')) {
		function wp_register_ability_category(string $id, array $args): void
		{
			WordPressTestState::$registered_ability_categories[$id] = $args;
		}
	}

	if (! function_exists('register_rest_route')) {
		function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
		{
			unset($override);

			$route_path   = '/' . trim($namespace, '/') . '/' . ltrim($route, '/');
			$is_list      = [] === $args
				|| array_keys($args) === range(0, count($args) - 1);
			$route_config = [
				'namespace' => trim($namespace, '/'),
				'route'     => '/' . ltrim($route, '/'),
				'path'      => $route_path,
				'endpoints' => $is_list ? $args : [$args],
				'raw'       => $args,
			];

			WordPressTestState::$rest_routes[$route_path] = $route_config;

			return true;
		}
	}

	if (! function_exists('register_block_pattern_category')) {
		function register_block_pattern_category(string $name, array $properties): bool
		{
			WordPressTestState::$registered_block_pattern_categories[$name] = $properties;

			return true;
		}
	}

	if (! function_exists('wp_remote_post')) {
		function wp_remote_post(string $url, array $args = [])
		{
			WordPressTestState::$last_remote_post = [
				'url'  => $url,
				'args' => $args,
			];
			WordPressTestState::$remote_post_calls[] = WordPressTestState::$last_remote_post;

			foreach (WordPressTestState::$remote_post_url_responses as $needle => $url_response) {
				if ('' !== $needle && str_contains($url, (string) $needle)) {
					return $url_response;
				}
			}

			if ([] !== WordPressTestState::$remote_post_responses) {
				return array_shift(WordPressTestState::$remote_post_responses);
			}

			if (empty(WordPressTestState::$remote_post_response)) {
				return new WP_Error('missing_remote_stub', 'No remote response stub configured.');
			}

			return WordPressTestState::$remote_post_response;
		}
	}

	if (! function_exists('wp_remote_get')) {
		function wp_remote_get(string $url, array $args = [])
		{
			WordPressTestState::$last_remote_get = [
				'url'  => $url,
				'args' => $args,
			];
			WordPressTestState::$remote_get_calls[] = WordPressTestState::$last_remote_get;

			if ([] !== WordPressTestState::$remote_get_responses) {
				return array_shift(WordPressTestState::$remote_get_responses);
			}

			if (empty(WordPressTestState::$remote_get_response)) {
				return new WP_Error('missing_remote_stub', 'No remote response stub configured.');
			}

			return WordPressTestState::$remote_get_response;
		}
	}

	if (! function_exists('wp_remote_request')) {
		function wp_remote_request(string $url, array $args = [])
		{
			$method = strtoupper((string) ($args['method'] ?? 'GET'));

			if ('GET' === $method) {
				return wp_remote_get($url, $args);
			}

			return wp_remote_post($url, $args);
		}
	}

	if (! function_exists('wp_remote_retrieve_body')) {
		function wp_remote_retrieve_body($response): string
		{
			return is_array($response) ? (string) ($response['body'] ?? '') : '';
		}
	}

	if (! function_exists('wp_remote_retrieve_response_code')) {
		function wp_remote_retrieve_response_code($response): int
		{
			if (! is_array($response)) {
				return 0;
			}

			return (int) ($response['response']['code'] ?? 0);
		}
	}

	if (! function_exists('wp_remote_retrieve_header')) {
		function wp_remote_retrieve_header($response, string $header)
		{
			if (! is_array($response) || ! is_array($response['headers'] ?? null)) {
				return false;
			}

			$normalized_header = strtolower($header);

			foreach ($response['headers'] as $key => $value) {
				if (strtolower((string) $key) === $normalized_header) {
					return $value;
				}
			}

			return false;
		}
	}

	if (! function_exists('wp_strip_all_tags')) {
		function wp_strip_all_tags(string $text): string
		{
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This test bootstrap defines the WordPress wrapper when core is unavailable.
			return strip_tags($text);
		}
	}

	if (! function_exists('wp_unslash')) {
		function wp_unslash($value)
		{
			if (is_array($value)) {
				return array_map('wp_unslash', $value);
			}

			return is_string($value) ? stripslashes($value) : $value;
		}
	}

	if (! function_exists('wp_slash')) {
		function wp_slash($value)
		{
			if (is_array($value)) {
				return array_map('wp_slash', $value);
			}

			return is_string($value) ? addslashes($value) : $value;
		}
	}

	if (! function_exists('add_settings_error')) {
		function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
		{
			WordPressTestState::$settings_errors[] = [
				'setting' => $setting,
				'code'    => $code,
				'message' => $message,
				'type'    => $type,
			];
		}
	}

	if (! function_exists('get_settings_errors')) {
		function get_settings_errors(string $setting = '', bool $sanitize = false): array
		{
			if (! empty($_GET['settings-updated'])) {
				$transient_errors = get_transient('settings_errors');

				if (is_array($transient_errors) && [] !== $transient_errors) {
					WordPressTestState::$settings_errors = array_merge(
						WordPressTestState::$settings_errors,
						$transient_errors
					);
					delete_transient('settings_errors');
				}
			}

			if ('' === $setting) {
				return WordPressTestState::$settings_errors;
			}

			return array_values(
				array_filter(
					WordPressTestState::$settings_errors,
					static fn(array $details): bool => ($details['setting'] ?? '') === $setting
				)
			);
		}
	}

	if (! function_exists('settings_errors')) {
		function settings_errors(string $setting = '', bool $sanitize = false, bool $hide_on_update = false): void
		{
			if ($hide_on_update && ! empty($_GET['settings-updated'])) {
				return;
			}

			$settings_errors = get_settings_errors($setting, $sanitize);

			if ([] === $settings_errors) {
				return;
			}

			foreach ($settings_errors as $details) {
				$type = (string) ($details['type'] ?? 'error');

				if ('updated' === $type) {
					$type = 'success';
				}

				printf(
					"<div class='notice notice-%s settings-error'><p><strong>%s</strong></p></div>\n",
					esc_attr($type),
					esc_html((string) ($details['message'] ?? ''))
				);
			}
		}
	}

	if (! function_exists('settings_fields')) {
		function settings_fields(string $option_group): void
		{
			printf(
				'<input type="hidden" name="option_page" value="%s" /><input type="hidden" name="action" value="update" />',
				esc_attr($option_group)
			);
		}
	}

	if (! function_exists('register_setting')) {
		function register_setting(string $option_group, string $option_name, array $args = []): void
		{
			$GLOBALS['wp_registered_settings'][$option_name] = array_merge(
				$args,
				[
					'option_group' => $option_group,
					'option_name'  => $option_name,
				]
			);
		}
	}

	if (! function_exists('add_settings_section')) {
		function add_settings_section(
			string $id,
			string $title,
			callable $callback,
			string $page
		): void {
			$GLOBALS['wp_settings_sections'][$page][$id] = [
				'id'       => $id,
				'title'    => $title,
				'callback' => $callback,
			];
		}
	}

	if (! function_exists('add_settings_field')) {
		function add_settings_field(
			string $id,
			string $title,
			callable $callback,
			string $page,
			string $section = 'default',
			array $args = []
		): void {
			$GLOBALS['wp_settings_fields'][$page][$section][$id] = [
				'id'       => $id,
				'title'    => $title,
				'callback' => $callback,
				'args'     => $args,
			];
		}
	}

	if (! function_exists('submit_button')) {
		function submit_button(string $text = 'Save Changes'): void
		{
			printf(
				'<button type="submit">%s</button>',
				esc_html($text)
			);
		}
	}

	if (! function_exists('get_post')) {
		function get_post($post_id = null)
		{
			$hook = WordPressTestState::$before_get_post;

			if (is_callable($hook)) {
				WordPressTestState::$before_get_post = null;
				$hook((int) $post_id);
			}

			if (WordPressTestState::$next_get_post_throws instanceof \Throwable) {
				$error = WordPressTestState::$next_get_post_throws;
				WordPressTestState::$next_get_post_throws = null;

				throw $error;
			}

			if (WordPressTestState::$next_get_post_returns_null) {
				WordPressTestState::$next_get_post_returns_null = false;

				return null;
			}

			if ($post_id === null) {
				return null;
			}

			$id = (int) (is_object($post_id) ? ($post_id->ID ?? 0) : $post_id);
			WordPressTestState::$get_post_calls[] = $id;

			return WordPressTestState::$posts[$id] ?? null;
		}
	}

	if (! function_exists('get_post_type')) {
		function get_post_type($post = null)
		{
			$id = (int) (is_object($post) ? ($post->ID ?? 0) : $post);
			WordPressTestState::$get_post_type_calls[] = $id;

			return isset(WordPressTestState::$posts[$id])
				? (string) (WordPressTestState::$posts[$id]->post_type ?? '')
				: false;
		}
	}

	if (! function_exists('wp_update_post')) {
		function wp_update_post(array $postarr, bool $wp_error = false)
		{
			$id = (int) ($postarr['ID'] ?? 0);

			if ($id <= 0 || ! isset(WordPressTestState::$posts[$id])) {
				return $wp_error
					? new \WP_Error('db_update_error', 'Could not update post in the database.')
					: 0;
			}

			$post_before        = clone WordPressTestState::$posts[$id];
			$meta_input         = is_array($postarr['meta_input'] ?? null) ? wp_unslash($postarr['meta_input']) : [];
			$database_data      = array_merge(wp_slash(get_object_vars(WordPressTestState::$posts[$id])), $postarr);
			unset($database_data['ID'], $database_data['meta_input'], $database_data['tax_input']);
			$database_data['post_modified']     = date('Y-m-d H:i:s');
			$database_data['post_modified_gmt'] = gmdate('Y-m-d H:i:s');
			$data_filter = 'attachment' === (string) ($database_data['post_type'] ?? '')
				? 'wp_insert_attachment_data'
				: 'wp_insert_post_data';
			$filtered = apply_filters($data_filter, $database_data, $postarr, $postarr, true);
			if (is_array($filtered)) {
				$database_data = wp_unslash($filtered);
			}
			$original_post_name = (string) ($database_data['post_name'] ?? '');

			do_action('pre_post_update', $id, $database_data);
			$updated = $GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts, $database_data, ['ID' => $id]);

			if (false === $updated) {
				return $wp_error
					? new \WP_Error('db_update_error', 'Could not update post in the database.')
					: 0;
			}

			if ('' !== $original_post_name && '' === (string) ($database_data['post_name'] ?? '')) {
				$GLOBALS['wpdb']->update(
					$GLOBALS['wpdb']->posts,
					['post_name' => 'generated-slug'],
					['ID' => $id]
				);
			}

			foreach ($meta_input as $meta_key => $meta_value) {
				update_post_meta($id, wp_slash((string) $meta_key), wp_slash($meta_value));
			}

			if (! isset(WordPressTestState::$posts[$id])) {
				throw new \RuntimeException('The post disappeared during its database update.');
			}

			do_action('post_updated', $id, clone WordPressTestState::$posts[$id], $post_before);

			WordPressTestState::$updated_posts[] = array_merge(['ID' => $id], $database_data);

			return $id;
		}
	}

	if (! function_exists('wp_insert_post')) {
		function wp_insert_post(array $postarr, bool $wp_error = false)
		{
			$unsanitized_postarr = $postarr;
			$tax_input           = is_array($postarr['tax_input'] ?? null) ? wp_unslash($postarr['tax_input']) : [];
			$meta_input          = is_array($postarr['meta_input'] ?? null) ? wp_unslash($postarr['meta_input']) : [];

			// Core normalizes post_name through sanitize_title() BEFORE the
			// wp_insert_post_data filter runs, so a caller-supplied slug that
			// sanitize_title() rewrites (`a--b` -> `a-b`, `-a-` -> `a`) is stored
			// rewritten. Modelling this is what lets the suite catch a caller that
			// compares its pre-insert slug against the stored post_name using a
			// different normalizer.
			if (isset($postarr['post_name']) && is_string($postarr['post_name'])) {
				$postarr['post_name'] = sanitize_title($postarr['post_name']);
			}

			$database_data = $postarr;
			unset($database_data['tax_input'], $database_data['meta_input']);

			$filtered = apply_filters('wp_insert_post_data', $database_data, $postarr, $unsanitized_postarr, false);
			if (is_array($filtered)) {
				$postarr = array_merge($postarr, $filtered);
			}
			$postarr              = wp_unslash($postarr);
			$postarr['tax_input'] = $tax_input;
			unset($postarr['meta_input']);

			do_action('pre_post_insert', $database_data, $postarr, $unsanitized_postarr, false);

			$insert_data = $postarr;
			unset($insert_data['tax_input']);
			$inserted = $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->posts, $insert_data);

			if (false === $inserted) {
				return $wp_error
					? new \WP_Error('db_insert_error', 'Could not insert post into the database.')
					: 0;
			}

			$id = 0;
			foreach (array_keys(WordPressTestState::$posts) as $existing) {
				$id = max($id, (int) $existing);
			}
			$id = $id > 0 ? $id + 1 : 5000;

			WordPressTestState::$posts[$id]       = new \WP_Post(array_merge($postarr, ['ID' => $id]));
			WordPressTestState::$inserted_posts[] = array_merge($postarr, ['ID' => $id]);

			if (! WordPressTestState::$skip_insert_taxonomy_assignment) {
				foreach ($tax_input as $taxonomy => $terms) {
					$terms = is_array($terms) ? $terms : [$terms];
					$terms = array_values(
						array_filter(
							array_map('strval', $terms),
							static fn(string $term): bool => '' !== $term
						)
					);
					$assigned = [] !== $terms;

					foreach ($terms as $term) {
						$term_taxonomy_id = ((int) sprintf('%u', crc32((string) $taxonomy . "\0" . $term)) % 100000) + 1;
						$inserted = $GLOBALS['wpdb']->insert(
							$GLOBALS['wpdb']->term_relationships,
							[
								'object_id'       => $id,
								'term_taxonomy_id' => $term_taxonomy_id,
							]
						);

						if (! $inserted) {
							$assigned = false;
							break;
						}
					}

					if ($assigned) {
						WordPressTestState::$object_terms[$id][(string) $taxonomy] = $terms;
						do_action('set_object_terms', $id, $terms, [], (string) $taxonomy, false, []);
					}
				}
			}

			foreach ($meta_input as $meta_key => $meta_value) {
				update_post_meta($id, wp_slash((string) $meta_key), wp_slash($meta_value));
			}

			do_action('wp_after_insert_post', $id, WordPressTestState::$posts[$id], false, null);

			return $id;
		}
	}

	if (! function_exists('wp_delete_post')) {
		function wp_delete_post(int $post_id, bool $force_delete = false)
		{
			unset($force_delete);

			if (! isset(WordPressTestState::$posts[$post_id])) {
				return false;
			}

			$post = WordPressTestState::$posts[$post_id];

			// A pre_delete_post filter can short-circuit deletion and still return
			// a WP_Post. Callers that trust the return value strand the row.
			if (WordPressTestState::$delete_post_short_circuits) {
				return $post;
			}

			unset(WordPressTestState::$posts[$post_id]);
			unset(WordPressTestState::$object_terms[$post_id]);
			WordPressTestState::$deleted_posts[] = $post_id;

			return $post;
		}
	}

	if (! function_exists('clean_post_cache')) {
		function clean_post_cache($post): void
		{
			WordPressTestState::$cleaned_post_caches[] = (int) (is_object($post) ? ($post->ID ?? 0) : $post);
		}
	}

	if (! class_exists('WP_Post')) {
		class WP_Post
		{

			public int $ID = 0;

			public string $post_title = '';

			public string $post_name = '';

			public string $post_content = '';

			public string $post_content_filtered = '';

			public string $post_excerpt = '';

			public string $post_status = 'publish';

			public string $post_type = 'post';

			public int $post_author = 0;

			public string $post_password = '';

			public string $post_date = '';

			public string $post_date_gmt = '';

			public string $comment_status = 'open';

			public string $ping_status = 'open';

			public string $to_ping = '';

			public string $pinged = '';

			public string $post_modified = '';

			public string $post_modified_gmt = '';

			public int $post_parent = 0;

			public int $menu_order = 0;

			public string $post_mime_type = '';

			public string $guid = '';

			/**
			 * @param array<string, mixed> $fields
			 */
			public function __construct(array $fields = [])
			{
				foreach ($fields as $key => $value) {
					if (property_exists($this, $key)) {
						$this->{$key} = $value;
					}
				}
			}
		}
	}

	if (! function_exists('mysql2date')) {
		function mysql2date(string $format, string $date, bool $translate = true): string
		{
			unset($translate);

			if ('' === $date) {
				return '';
			}

			$timestamp = strtotime($date);
			if (false === $timestamp) {
				return '';
			}

			return date($format, $timestamp);
		}
	}

	if (! function_exists('setup_postdata')) {
		function setup_postdata($post): bool
		{
			if ($post instanceof WP_Post) {
				WordPressTestState::$current_post = $post;

				return true;
			}

			return false;
		}
	}

	if (! function_exists('wp_reset_postdata')) {
		function wp_reset_postdata(): void
		{
			WordPressTestState::$current_post = null;
		}
	}

	if (! function_exists('register_block_type')) {
		function register_block_type(string $name, array $args = []): object
		{
			\WP_Block_Type_Registry::get_instance()->register($name, $args);
			$registered = \WP_Block_Type_Registry::get_instance()->get_registered($name);

			return is_object($registered)
				? $registered
				: (object) array_merge($args, ['name' => $name]);
		}
	}

	if (! function_exists('render_block')) {
		function render_block(array $block): string
		{
			$name = $block['blockName'] ?? null;

			if (null === $name) {
				return (string) ($block['innerHTML'] ?? '');
			}

			$rendered_inner  = '';
			$inner_blocks    = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
			$inner_block_idx = 0;
			$inner_content   = $block['innerContent'] ?? [$block['innerHTML'] ?? ''];

			if (! is_array($inner_content)) {
				$inner_content = [(string) $inner_content];
			}

			foreach ($inner_content as $chunk) {
				if (is_string($chunk)) {
					$rendered_inner .= $chunk;
					continue;
				}

				$next = $inner_blocks[$inner_block_idx++] ?? null;
				if (is_array($next)) {
					$rendered_inner .= render_block($next);
				}
			}

			$registered      = \WP_Block_Type_Registry::get_instance()->get_registered((string) $name);
			$render_callback = is_object($registered) ? ($registered->render_callback ?? null) : null;

			if (is_callable($render_callback)) {
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

	if (! function_exists('parse_blocks')) {
		function parse_blocks(string $content): array
		{
			if ('' === $content) {
				return [];
			}

			$blocks = [];
			$offset = 0;
			$length = strlen($content);

			while ($offset < $length) {
				$next = _flavor_agent_parse_next_block($content, $offset);

				if (null === $next) {
					$remainder = substr($content, $offset);
					if ('' !== $remainder) {
						$blocks[] = _flavor_agent_make_freeform_block($remainder);
					}
					break;
				}

				if ($next['start'] > $offset) {
					$freeform = substr($content, $offset, $next['start'] - $offset);
					if ('' !== $freeform) {
						$blocks[] = _flavor_agent_make_freeform_block($freeform);
					}
				}

				$blocks[] = $next['parsed'];
				$offset   = $next['end'];
			}

			return $blocks;
		}
	}

	if (! function_exists('_flavor_agent_make_freeform_block')) {
		function _flavor_agent_make_freeform_block(string $html): array
		{
			return [
				'blockName'    => null,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [$html],
			];
		}
	}

	if (! function_exists('_flavor_agent_parse_next_block')) {
		function _flavor_agent_parse_next_block(string $content, int $offset): ?array
		{
			$pattern = '/<!--\s+wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)\s*(\{.*?\})?\s*(\/)?-->/s';

			if (! preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
				return null;
			}

			$full_match   = $match[0][0];
			$match_pos    = $match[0][1];
			$short_name   = $match[1][0];
			$block_name   = str_contains($short_name, '/') ? $short_name : 'core/' . $short_name;
			$attrs_json   = $match[2][0] ?? '';
			$self_closing = ! empty($match[3][0]);

			$attrs = [];
			if ('' !== $attrs_json) {
				$decoded = json_decode($attrs_json, true);
				if (is_array($decoded)) {
					$attrs = $decoded;
				}
			}

			$opening_end = $match_pos + strlen($full_match);

			if ($self_closing) {
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
			$same_open_regex = '/<!--\s+wp:' . preg_quote($short_name, '/') . '(?:\s+\{.*?\})?\s*(\/)?-->/s';
			$depth           = 1;
			$scan_pos        = $opening_end;
			$close_pos       = -1;

			while ($scan_pos < strlen($content)) {
				$next_open  = preg_match($same_open_regex, $content, $same_open_match, PREG_OFFSET_CAPTURE, $scan_pos)
					? $same_open_match[0][1]
					: false;
				$next_close = strpos($content, $close_tag, $scan_pos);

				if (false === $next_close) {
					break;
				}

				if (false !== $next_open && $next_open < $next_close) {
					if (empty($same_open_match[1][0])) {
						++$depth;
					}
					$scan_pos = $next_open + strlen((string) $same_open_match[0][0]);
					continue;
				}

				--$depth;
				if (0 === $depth) {
					$close_pos = $next_close;
					break;
				}

				$scan_pos = $next_close + strlen($close_tag);
			}

			if ($close_pos < 0) {
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

			while ($inner_offset < $inner_end) {
				$child = _flavor_agent_parse_next_block($content, $inner_offset);

				if (null === $child || $child['start'] >= $inner_end) {
					$tail = substr($content, $inner_offset, $inner_end - $inner_offset);
					if ('' !== $tail) {
						$inner_content[] = $tail;
						$inner_html     .= $tail;
					}
					break;
				}

				if ($child['start'] > $inner_offset) {
					$prefix = substr($content, $inner_offset, $child['start'] - $inner_offset);
					if ('' !== $prefix) {
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
				'end'    => $close_pos + strlen($close_tag),
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

	if (! function_exists('str_starts_with')) {
		function str_starts_with(string $haystack, string $needle): bool
		{
			return strncmp($haystack, $needle, strlen($needle)) === 0;
		}
	}

	if (! function_exists('strip_core_block_namespace')) {
		function strip_core_block_namespace(?string $block_name = null): ?string
		{
			if (is_string($block_name) && str_starts_with($block_name, 'core/')) {
				$block_name = substr($block_name, 5);
			}

			return $block_name;
		}
	}

	if (! function_exists('serialize_block_attributes')) {
		function serialize_block_attributes(array $block_attributes): string
		{
			$encoded_attributes = wp_json_encode(
				$block_attributes,
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);

			$encoded_attributes = preg_replace('/--/', '\\u002d\\u002d', (string) $encoded_attributes);
			$encoded_attributes = preg_replace('/</', '\\u003c', $encoded_attributes);
			$encoded_attributes = preg_replace('/>/', '\\u003e', $encoded_attributes);
			$encoded_attributes = preg_replace('/&/', '\\u0026', $encoded_attributes);
			$encoded_attributes = preg_replace('/\\\\"/', '\\u0022', $encoded_attributes);

			return $encoded_attributes;
		}
	}

	if (! function_exists('get_comment_delimited_block_content')) {
		function get_comment_delimited_block_content(?string $block_name, array $block_attributes, string $block_content): string
		{
			if (null === $block_name) {
				return $block_content;
			}

			$serialized_block_name = strip_core_block_namespace($block_name);
			$serialized_attributes = empty($block_attributes)
				? ''
				: serialize_block_attributes($block_attributes) . ' ';

			if ('' === $block_content) {
				return sprintf('<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes);
			}

			return sprintf(
				'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
				$serialized_block_name,
				$serialized_attributes,
				$block_content,
				$serialized_block_name
			);
		}
	}

	if (! function_exists('serialize_block')) {
		function serialize_block(array $block): string
		{
			$block_content = '';

			$index         = 0;
			$inner_content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];
			$inner_blocks  = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

			foreach ($inner_content as $chunk) {
				if (is_string($chunk)) {
					$block_content .= $chunk;
					continue;
				}

				$next = $inner_blocks[$index++] ?? null;
				if (is_array($next)) {
					$block_content .= serialize_block($next);
				}
			}

			$attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

			return get_comment_delimited_block_content(
				$block['blockName'] ?? null,
				$attrs,
				$block_content
			);
		}
	}

	if (! function_exists('serialize_blocks')) {
		function serialize_blocks(array $blocks): string
		{
			return implode('', array_map('serialize_block', $blocks));
		}
	}

	if (! function_exists('update_option')) {
		function update_option(string $name, $value, $autoload = null): bool
		{
			global $wpdb;

			$table = is_object($wpdb) && isset($wpdb->options) ? (string) $wpdb->options : 'wp_options';

			if ('wp_options' !== $table) {
				$rows    = WordPressTestState::$db_tables[$table] ?? [];
				$updated = false;

				foreach ($rows as $index => $row) {
					if ($name !== (string) ($row['option_name'] ?? '')) {
						continue;
					}

					$rows[$index]['option_value'] = $value;
					if (null !== $autoload) {
						$rows[$index]['autoload'] = $autoload;
					}
					$updated = true;
					break;
				}

				if (! $updated) {
					$rows[] = [
						'option_name'  => $name,
						'option_value' => $value,
						'autoload'     => null !== $autoload ? $autoload : 'yes',
					];
				}

				WordPressTestState::$db_tables[$table] = $rows;

				return true;
			}

			WordPressTestState::$options[$name] = $value;
			WordPressTestState::$updated_options[$name] = $value;
			unset( WordPressTestState::$option_notoptions_cache[ $name ] );
			if (null !== $autoload) {
				WordPressTestState::$option_autoload[$name] = $autoload;
			}

			return true;
		}
	}

	if (! function_exists('add_option')) {
		function add_option(string $name, $value = '', string $deprecated = '', $autoload = 'yes'): bool
		{
			global $wpdb;

			unset($deprecated);

			$table = is_object($wpdb) && isset($wpdb->options) ? (string) $wpdb->options : 'wp_options';

			if ('wp_options' !== $table) {
				$rows = WordPressTestState::$db_tables[$table] ?? [];

				foreach ($rows as $row) {
					if ($name === (string) ($row['option_name'] ?? '')) {
						return false;
					}
				}

				$rows[] = [
					'option_name'  => $name,
					'option_value' => $value,
					'autoload'     => $autoload,
				];
				WordPressTestState::$db_tables[$table] = $rows;

				return true;
			}

			if (array_key_exists($name, WordPressTestState::$options)) {
				return false;
			}

			WordPressTestState::$options[$name]         = $value;
			WordPressTestState::$option_autoload[$name] = $autoload;
			unset( WordPressTestState::$option_notoptions_cache[ $name ] );

			return true;
		}
	}

	if (! function_exists('delete_option')) {
		function delete_option(string $name): bool
		{
			unset(
				WordPressTestState::$options[$name],
				WordPressTestState::$updated_options[$name],
				WordPressTestState::$option_autoload[$name]
			);

			return true;
		}
	}

	if (! function_exists('wp_cache_delete')) {
		function wp_cache_delete(string $key, string $group = ''): bool
		{
			if (WordPressTestState::$wp_cache_delete_throws) {
				throw new \RuntimeException('object cache unavailable');
			}

			if ( 'options' === $group && 'notoptions' === $key ) {
				WordPressTestState::$option_notoptions_cache = [];
			}

			return true;
		}
	}

	if (! function_exists('wp_schedule_event')) {
		function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
		{
			WordPressTestState::$scheduled_events[$hook] = [
				'hook'       => $hook,
				'timestamp'  => $timestamp,
				'recurrence' => $recurrence,
				'args'       => $args,
			];

			return true;
		}
	}

	if (! function_exists('wp_schedule_single_event')) {
		function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
		{
			WordPressTestState::$scheduled_events[$hook] = [
				'hook'      => $hook,
				'timestamp' => $timestamp,
				'args'      => $args,
			];

			return true;
		}
	}

	if (! function_exists('wp_next_scheduled')) {
		function wp_next_scheduled(string $hook, array $args = [])
		{
			if (isset(WordPressTestState::$scheduled_events[$hook])) {
				return WordPressTestState::$scheduled_events[$hook]['timestamp'];
			}

			return false;
		}
	}

	if (! function_exists('wp_clear_scheduled_hook')) {
		function wp_clear_scheduled_hook(string $hook, array $args = []): int
		{
			WordPressTestState::$cleared_cron_hooks[] = $hook;
			unset(WordPressTestState::$scheduled_events[$hook]);

			return 1;
		}
	}

	require dirname(__DIR__, 2) . '/vendor/autoload.php';

	if (! isset($GLOBALS['wpdb']) || ! $GLOBALS['wpdb'] instanceof wpdb) {
		$GLOBALS['wpdb'] = new wpdb();
	}

	WordPressTestState::reset();
}
