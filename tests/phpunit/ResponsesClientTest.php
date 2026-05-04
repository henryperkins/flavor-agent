<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class ResponsesClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		WordPressTestState::reset();
	}

	private function configure_openai_connector(): void {
		WordPressTestState::$options                    = [
			Provider::OPTION_NAME => 'openai',
		];
		WordPressTestState::$connectors                 = [
			'openai' => [
				'name'           => 'OpenAI',
				'description'    => 'OpenAI connector',
				'type'           => 'ai_provider',
				'authentication' => [
					'method'       => 'api_key',
					'setting_name' => 'connectors_ai_openai_api_key',
				],
			],
		];
		WordPressTestState::$ai_client_provider_support = [
			'openai' => true,
		];
		WordPressTestState::$ai_client_supported        = true;
	}

	public function test_validate_configuration_returns_true_when_chat_provider_is_configured(): void {
		$this->configure_openai_connector();

		$this->assertTrue( ResponsesClient::validate_configuration() );
	}

	public function test_validate_configuration_returns_wp_error_when_no_provider_is_configured(): void {
		$result = ResponsesClient::validate_configuration();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'responses_validation_error', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_validate_configuration_ignores_legacy_endpoint_api_key_and_deployment_arguments(): void {
		$result = ResponsesClient::validate_configuration(
			'https://example.openai.azure.com/',
			'azure-key',
			'chat-deployment'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'responses_validation_error', $result->get_error_code() );
	}

	public function test_validate_configuration_with_explicit_provider_routes_to_that_provider_config(): void {
		WordPressTestState::$connectors                 = [
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
		WordPressTestState::$ai_client_provider_support = [
			'anthropic' => true,
		];
		WordPressTestState::$ai_client_supported        = true;

		$this->assertTrue( ResponsesClient::validate_configuration( null, null, null, 'anthropic' ) );
	}

	public function test_rank_returns_wp_error_when_no_text_generation_provider_is_configured(): void {
		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text_generation_provider', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_rank_routes_through_wordpress_ai_client_with_pinned_connector(): void {
		$this->configure_openai_connector();
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"ok"}';

		$result = ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( '{"explanation":"ok"}', $result );
		$this->assertSame( 'openai', WordPressTestState::$last_ai_client_prompt['provider'] ?? null );
	}

	public function test_rank_uses_the_explicit_reasoning_effort_argument(): void {
		$this->configure_openai_connector();
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"ok"}';

		ResponsesClient::rank( 'system prompt', 'user prompt', 'high' );

		$this->assertSame( 'high', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
	}

	public function test_rank_falls_back_to_saved_reasoning_effort_option_when_no_explicit_value(): void {
		$this->configure_openai_connector();
		WordPressTestState::$options['flavor_agent_azure_reasoning_effort'] = 'xhigh';
		WordPressTestState::$ai_client_generate_text_result                 = '{"explanation":"ok"}';

		ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( 'xhigh', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
	}

	public function test_rank_falls_back_to_default_medium_when_saved_reasoning_effort_is_invalid(): void {
		$this->configure_openai_connector();
		WordPressTestState::$options['flavor_agent_azure_reasoning_effort'] = 'definitely-not-valid';
		WordPressTestState::$ai_client_generate_text_result                 = '{"explanation":"ok"}';

		ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( 'medium', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
	}

	public function test_rank_rejects_invalid_explicit_reasoning_effort_and_falls_back_to_saved_then_default(): void {
		$this->configure_openai_connector();
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"ok"}';

		ResponsesClient::rank( 'system prompt', 'user prompt', 'shouting' );

		$this->assertSame( 'medium', WordPressTestState::$last_ai_client_prompt['reasoning'] ?? null );
	}

	public function test_rank_passes_schema_through_to_wordpress_ai_client(): void {
		$this->configure_openai_connector();
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"ok"}';

		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'explanation' => [ 'type' => 'string' ],
			],
			'required'             => [ 'explanation' ],
		];

		ResponsesClient::rank( 'system prompt', 'user prompt', null, $schema );

		$this->assertSame(
			$schema,
			WordPressTestState::$last_ai_client_prompt['json_schema'] ?? null
		);
	}

	public function test_rank_resolves_ability_name_from_schema_name_for_system_instruction_filter(): void {
		$this->configure_openai_connector();
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"ok"}';
		$seen_ability                                       = null;

		add_filter(
			'wpai_system_instruction',
			static function ( string $instruction, string $ability ) use ( &$seen_ability ): string {
				$seen_ability = $ability;

				return $instruction;
			},
			10,
			3
		);

		ResponsesClient::rank(
			'system prompt',
			'user prompt',
			null,
			null,
			'flavor_agent_template'
		);

		$this->assertSame( 'flavor-agent/recommend-template', $seen_ability );
	}

	public function test_rank_falls_back_to_generic_ability_name_when_schema_name_is_unknown(): void {
		$this->configure_openai_connector();
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"ok"}';
		$seen_ability                                       = null;

		add_filter(
			'wpai_system_instruction',
			static function ( string $instruction, string $ability ) use ( &$seen_ability ): string {
				$seen_ability = $ability;

				return $instruction;
			},
			10,
			3
		);

		ResponsesClient::rank(
			'system prompt',
			'user prompt',
			null,
			null,
			'unrecognized_schema'
		);

		$this->assertSame( 'flavor-agent', $seen_ability );
	}

	public function test_rank_does_no_direct_remote_post(): void {
		$this->configure_openai_connector();
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"ok"}';

		ResponsesClient::rank( 'system prompt', 'user prompt' );

		$this->assertSame( [], WordPressTestState::$last_remote_post );
	}
}
