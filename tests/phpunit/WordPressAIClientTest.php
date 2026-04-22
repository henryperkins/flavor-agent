<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class WordPressAIClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	public function test_is_supported_accepts_magic_method_prompt_builders(): void {
		$prompt = wp_ai_client_prompt( 'Flavor Agent availability check.' );

		$this->assertInstanceOf( \WP_AI_Client_Prompt_Builder::class, $prompt );
		$this->assertFalse( method_exists( $prompt, 'is_supported_for_text_generation' ) );
		$this->assertTrue( is_callable( [ $prompt, 'is_supported_for_text_generation' ] ) );

		WordPressTestState::$ai_client_supported = true;

		$this->assertTrue( WordPressAIClient::is_supported() );
		$this->assertSame( 'core_function', WordPressTestState::$last_ai_client_prompt['transport'] ?? null );
	}

	public function test_is_supported_can_target_a_specific_provider(): void {
		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];

		$this->assertTrue( WordPressAIClient::is_supported( 'anthropic' ) );
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
	}

	public function test_chat_returns_setup_error_when_no_text_generation_provider_is_available(): void {
		WordPressTestState::$ai_client_supported = false;

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text_generation_provider', $result->get_error_code() );
		$this->assertSame( WordPressAIClient::get_setup_message(), $result->get_error_message() );
	}

	public function test_chat_applies_system_instruction_and_returns_generated_text(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 'core_function', WordPressTestState::$last_ai_client_prompt['transport'] ?? null );
		$this->assertSame(
			'WordPress Gutenberg block styling and configuration assistant.',
			WordPressTestState::$last_ai_client_prompt['system'] ?? null
		);
	}

	public function test_chat_locks_the_prompt_to_the_requested_provider(): void {
		WordPressTestState::$ai_client_provider_support     = [
			'anthropic' => true,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			'anthropic'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 'anthropic', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
	}

	public function test_chat_applies_reasoning_effort_when_the_prompt_builder_supports_it(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			null,
			'high'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
	}

	public function test_chat_records_token_and_latency_metrics_when_the_ai_client_returns_structured_metadata(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = [
			'text'       => '{"explanation":"Use the accent color."}',
			'tokenUsage' => [
				'total'  => 42,
				'input'  => 12,
				'output' => 30,
			],
			'latencyMs'  => 321,
		];

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);
		$meta   = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 42, $meta['tokenUsage']['total'] ?? null );
		$this->assertSame( 12, $meta['tokenUsage']['input'] ?? null );
		$this->assertSame( 30, $meta['tokenUsage']['output'] ?? null );
		$this->assertSame( 321, $meta['latencyMs'] ?? null );
	}
}
