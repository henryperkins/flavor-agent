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
	'init',
	static function (): void {
		$stubbed_options = [
			'flavor_agent_openai_provider'               => 'openai_native',
			'flavor_agent_openai_native_api_key'         => 'playground-key',
			'flavor_agent_openai_native_embedding_model' => 'playground-embeddings',
			'flavor_agent_qdrant_url'                 => 'https://example.test/qdrant',
			'flavor_agent_qdrant_key'                 => 'playground-qdrant-key',
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
}
