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

	public function test_is_supported_when_wordpress_ai_client_has_a_text_generation_provider(): void {
		WordPressTestState::$ai_client_supported = true;

		$this->assertTrue( ChatClient::is_supported() );
	}

	public function test_is_not_supported_when_wordpress_ai_client_has_no_text_generation_provider(): void {
		WordPressTestState::$ai_client_supported = false;

		$this->assertFalse( ChatClient::is_supported() );
	}

	public function test_chat_routes_through_the_wordpress_ai_client(): void {
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

	public function test_selected_connector_provider_pins_chat_to_that_connector(): void {
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
