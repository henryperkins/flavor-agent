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
}
