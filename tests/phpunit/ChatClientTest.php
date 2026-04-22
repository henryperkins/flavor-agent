<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\ChatClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ChatClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_is_supported_when_active_flavor_agent_chat_backend_is_configured(): void {
		WordPressTestState::$options = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'gpt-5.4',
		];

		$this->assertTrue( ChatClient::is_supported() );
	}

	public function test_chat_prefers_active_flavor_agent_provider_over_wordpress_ai_client(): void {
		WordPressTestState::$options              = [
			'flavor_agent_azure_openai_endpoint' => 'https://example.openai.azure.com/',
			'flavor_agent_azure_openai_key'      => 'azure-key',
			'flavor_agent_azure_chat_deployment' => 'gpt-5.4',
		];
		WordPressTestState::$ai_client_supported  = true;
		WordPressTestState::$remote_post_response = [
			'response' => [
				'code' => 200,
			],
			'body'     => wp_json_encode(
				[
					'output_text' => '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
				]
			),
		];

		$result = ChatClient::chat( 'system prompt', 'user prompt' );

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);
		$this->assertSame(
			'https://example.openai.azure.com/openai/v1/responses',
			WordPressTestState::$last_remote_post['url']
		);
		$this->assertSame( [], WordPressTestState::$last_ai_client_prompt );
	}

	public function test_chat_falls_back_to_wordpress_ai_client_when_plugin_chat_backend_is_not_configured(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		$result = ChatClient::chat( 'system prompt', 'user prompt' );

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);
		$this->assertSame( 'core_function', WordPressTestState::$last_ai_client_prompt['transport'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}

	public function test_chat_returns_unified_setup_message_when_no_backend_is_available(): void {
		$result = ChatClient::chat( 'system prompt', 'user prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text_generation_provider', $result->get_error_code() );
		$this->assertSame( ChatClient::get_setup_message(), $result->get_error_message() );
	}

	public function test_selected_connector_provider_routes_block_recommendations_through_the_wordpress_ai_client(): void {
		WordPressTestState::$options                        = [
			'flavor_agent_openai_provider' => 'anthropic',
		];
		WordPressTestState::$connectors                     = [
			'anthropic' => [
				'name'           => 'Anthropic',
				'description'    => 'Anthropic connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_anthropic_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support     = [
			'anthropic' => true,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		$result = ChatClient::chat( 'system prompt', 'user prompt' );

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}
}
