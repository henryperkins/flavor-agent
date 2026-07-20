<?php

/**
 * Flavor Agent Playground loader.
 *
 * Loads the plugin in WordPress Playground without requiring standard
 * activation so browser smoke tests can run against a stable editor build.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

if (! class_exists('WordPress\\AI\\Abstracts\\Abstract_Ability')) {
	eval(
		'namespace WordPress\\AI\\Abstracts;
		abstract class Abstract_Ability {
			public function __construct(protected string $name, array $properties = []) {}
			public function input_schema(): array { return []; }
			public function output_schema(): array { return []; }
			public function execute_callback(mixed $input): mixed { return $input; }
			public function permission_callback(mixed $input = null): bool { unset($input); return true; }
			public function meta(): array { return []; }
			public function category(): string { return ""; }
		}'
	);
}

if (! class_exists('WordPress\\AI\\Abstracts\\Abstract_Feature')) {
	eval(
		'namespace WordPress\\AI\\Abstracts;
		abstract class Abstract_Feature {
			final public function __construct() {}
			public static function get_id(): string { return ""; }
			abstract protected function load_metadata(): array;
			abstract public function register(): void;
		}'
	);
}

if (! class_exists('WordPress\\AI\\Experiments\\Experiment_Category')) {
	eval(
		'namespace WordPress\\AI\\Experiments;
		final class Experiment_Category {
			public const EDITOR = "editor";
			public const ADMIN = "admin";
		}'
	);
}

if (! function_exists('wp_register_ability')) {
	function wp_register_ability(string $id, array $args): void
	{
		unset($id, $args);
	}
}

if (! function_exists('wp_register_ability_category')) {
	function wp_register_ability_category(string $id, array $args): void
	{
		unset($id, $args);
	}
}

if (! function_exists('wp_ai_client_prompt')) {
	/**
	 * Minimal WordPress AI Client test double for Playground smoke tests.
	 *
	 * The browser specs route-mock Flavor Agent REST responses; this only keeps
	 * server-localized capability flags aligned with Connectors-only chat.
	 */
	function wp_ai_client_prompt(string $prompt): object
	{
		unset($prompt);

		return new class() {
			public function is_supported_for_text_generation(): bool
			{
				return true;
			}

			public function using_provider(string $provider): self
			{
				return $this;
			}

			public function using_system_instruction(string $system_instruction): self
			{
				return $this;
			}

			public function using_reasoning_effort(string $reasoning_effort): self
			{
				return $this;
			}

			public function using_reasoning(mixed $reasoning): self
			{
				return $this;
			}

			public function as_json_response(array $schema): self
			{
				return $this;
			}

			public function generate_text(): string
			{
				return '{}';
			}

			public function generate_text_result(): string
			{
				return $this->generate_text();
			}
		};
	}
}

add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		if (! function_exists('wp_register_script_module')) {
			return;
		}

		wp_register_script_module(
			'@wordpress/core-abilities',
			content_url('mu-plugins/flavor-agent-playground-core-abilities.js'),
			[],
			false
		);
		wp_register_script_module(
			'@wordpress/abilities',
			content_url('mu-plugins/flavor-agent-playground-abilities.js'),
			[],
			false
		);
		wp_enqueue_script(
			'flavor-agent-playground-block-styles',
			content_url('mu-plugins/flavor-agent-playground-block-styles.js'),
			[ 'wp-blocks', 'wp-data', 'wp-dom-ready' ],
			'1',
			true
		);
	},
	0
);

add_action(
	'init',
	static function (): void {
		register_block_style(
			'core/paragraph',
			[
				'name'  => 'fa-e2e-registry',
				'label' => 'FA E2E Registry Style',
			]
		);
	}
);

add_action(
	'init',
	static function (): void {
		$stubbed_options = [
			'wpai_features_enabled'                         => '1',
			'wpai_feature_flavor-agent_enabled'             => '1',
			'flavor_agent_openai_provider'                 => 'cloudflare_workers_ai',
			'flavor_agent_cloudflare_workers_ai_account_id' => 'playground-account',
			'flavor_agent_cloudflare_workers_ai_api_token'  => 'playground-workers-token',
			'flavor_agent_cloudflare_workers_ai_embedding_model' => '@cf/qwen/qwen3-embedding-0.6b',
			'flavor_agent_qdrant_url'                      => 'https://example.test/qdrant',
			'flavor_agent_qdrant_key'                      => 'playground-qdrant-key',
		];

		foreach ($stubbed_options as $flavor_agent_option_name => $value) {
			if (get_option($flavor_agent_option_name) !== $value) {
				update_option($flavor_agent_option_name, $value);
			}
		}
	},
	0
);

$flavor_agent_plugin_main = WP_CONTENT_DIR . '/plugins/flavor-agent/flavor-agent.php';

if (file_exists($flavor_agent_plugin_main)) {
	require_once $flavor_agent_plugin_main;

	if (class_exists('FlavorAgent\\AI\\FlavorAgentFeature')) {
		(new FlavorAgent\AI\FlavorAgentFeature())->register();
	}
}
