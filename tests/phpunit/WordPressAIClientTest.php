<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\ResponseSchema;
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

	public function test_chat_extracts_message_from_structured_ai_client_error_payload(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = new \WP_Error(
			'wp_ai_client_request_failed',
			"{'message': 'Your access token could not be refreshed because your refresh token was revoked. Please log out and sign in again.', 'codexErrorInfo': 'unauthorized', 'additionalDetails': None}",
			[ 'status' => 401 ]
		);

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);
		$meta   = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_ai_client_request_failed', $result->get_error_code() );
		$this->assertSame(
			'Your access token could not be refreshed because your refresh token was revoked. Please log out and sign in again.',
			$result->get_error_message()
		);
		$this->assertSame(
			'Your access token could not be refreshed because your refresh token was revoked. Please log out and sign in again.',
			$meta['errorSummary']['wrappedMessage'] ?? null
		);
	}

	public function test_chat_returns_prompt_prevented_error_when_filter_blocks_generation(): void {
		WordPressTestState::$ai_client_supported = true;
		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'prompt_prevented', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] ?? null );
	}

	public function test_chat_translates_prompt_prevented_exception_thrown_during_generation(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$call_count = 0;
		add_filter(
			'wp_ai_client_prevent_prompt',
			static function ( bool $prevent ) use ( &$call_count ): bool {
				++$call_count;

				return $call_count > 1;
			},
			10,
			2
		);

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'prompt_prevented', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] ?? null );
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

	public function test_chat_routes_through_ai_service_with_supported_generation_options(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			null,
			null,
			null,
			[
				'temperature'       => '0.3',
				'max_tokens'        => '500',
				'candidate_count'   => 2,
				'top_p'             => 0.8,
				'top_k'             => 40,
				'stop_sequences'    => [ 'END', 123, '' ],
				'presence_penalty'  => 0.1,
				'frequency_penalty' => 0.2,
				'logprobs'          => true,
				'top_logprobs'      => 5,
				'ignored_option'    => 'must not pass through',
			],
			'flavor-agent/recommend-block'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertCount( 1, WordPressTestState::$ai_service_calls );
		$this->assertSame( 'Recommend a better block.', WordPressTestState::$ai_service_calls[0]['prompt'] );
		$this->assertSame(
			[
				'system_instruction' => 'WordPress Gutenberg block styling and configuration assistant.',
				'candidate_count'    => 2,
				'max_tokens'         => 500,
				'temperature'        => 0.3,
				'top_p'              => 0.8,
				'top_k'              => 40,
				'stop_sequences'     => [ 'END', '123' ],
				'presence_penalty'   => 0.1,
				'frequency_penalty'  => 0.2,
				'logprobs'           => true,
				'top_logprobs'       => 5,
			],
			WordPressTestState::$ai_service_calls[0]['options']
		);
		$this->assertArrayNotHasKey( 'ignored_option', WordPressTestState::$ai_service_calls[0]['options'] );
	}

	public function test_chat_preserves_model_options_when_ai_service_throws(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';
		WordPressTestState::$ai_service_call_throws         = new \RuntimeException( 'AI service unavailable' );

		$result = WordPressAIClient::chat(
			'System.',
			'User.',
			null,
			null,
			null,
			[
				'temperature' => 0.4,
				'max_tokens'  => 250,
			]
		);

		$this->assertSame( '{"explanation":"OK."}', $result );
		$this->assertSame(
			0.4,
			WordPressTestState::$last_ai_client_prompt['model_config']['temperature'] ?? null
		);
		$this->assertSame(
			250,
			WordPressTestState::$last_ai_client_prompt['model_config']['max_tokens'] ?? null
		);
	}

	public function test_chat_applies_preferred_text_models_to_ai_client_prompt_fallback(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';
		WordPressTestState::$ai_service_call_throws         = new \RuntimeException( 'AI service unavailable' );
		WordPressTestState::$preferred_text_models          = [
			'openai:gpt-5-mini',
			'anthropic:claude-sonnet-4.6',
		];

		$result = WordPressAIClient::chat(
			'System.',
			'User.',
			null,
			null,
			null,
			[
				'temperature' => 0.2,
			]
		);

		$this->assertSame( '{"explanation":"OK."}', $result );
		$this->assertSame(
			[
				'openai:gpt-5-mini',
				'anthropic:claude-sonnet-4.6',
			],
			WordPressTestState::$last_ai_client_prompt['model_preferences'] ?? null
		);
		$this->assertSame(
			0.2,
			WordPressTestState::$last_ai_client_prompt['model_config']['temperature'] ?? null
		);
	}

	public function test_chat_sends_compact_block_schema_for_generic_wordpress_ai_client_fallback(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			null,
			'medium',
			ResponseSchema::get( 'block' )
		);

		$this->assertSame(
			'{"settings":[],"styles":[],"block":[],"explanation":"Use the accent color."}',
			$result
		);

		$schema = WordPressTestState::$last_ai_client_prompt['json_schema'] ?? null;

		$this->assertIsArray( $schema );
		$this->assertSame( 0, self::count_schema_unions( $schema ) );

		$block_properties = $schema['properties']['block']['items']['properties'] ?? [];
		$this->assertArrayHasKey( 'operations', $block_properties );
		$this->assertArrayNotHasKey( 'proposedOperations', $block_properties );
		$this->assertArrayNotHasKey( 'rejectedOperations', $block_properties );
		$this->assertSame(
			'string',
			$schema['properties']['settings']['items']['properties']['attributeUpdates']['type'] ?? null
		);
		$this->assertSame(
			'number',
			$schema['properties']['settings']['items']['properties']['confidence']['type'] ?? null
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

	public function test_chat_passes_codex_reasoning_effort_through_model_config_custom_options(): void {
		WordPressTestState::$ai_client_provider_support     = [
			'codex' => true,
		];
		WordPressTestState::$ai_client_feature_support      = [
			'reasoning' => false,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			'codex',
			'high'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertArrayNotHasKey( 'reasoning', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame(
			[ 'reasoningEffort' => 'high' ],
			WordPressTestState::$last_ai_client_prompt['customOptions'] ?? null
		);
	}

	public function test_chat_passes_openai_reasoning_effort_through_model_config_custom_options(): void {
		WordPressTestState::$ai_client_provider_support     = [
			'openai' => true,
		];
		WordPressTestState::$ai_client_feature_support      = [
			'reasoning' => false,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			'openai',
			'high'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertArrayNotHasKey( 'reasoning', WordPressTestState::$last_ai_client_prompt );
		$this->assertSame(
			[
				'reasoning' => [
					'effort' => 'high',
				],
			],
			WordPressTestState::$last_ai_client_prompt['customOptions'] ?? null
		);
	}

	public function test_chat_does_not_guess_anthropic_reasoning_custom_options(): void {
		WordPressTestState::$ai_client_provider_support     = [
			'anthropic' => true,
		];
		WordPressTestState::$ai_client_feature_support      = [
			'reasoning' => false,
		];
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			'anthropic',
			'high'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertArrayNotHasKey( 'reasoning', WordPressTestState::$last_ai_client_prompt );
		$this->assertArrayNotHasKey( 'customOptions', WordPressTestState::$last_ai_client_prompt );
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

	public function test_chat_records_token_usage_from_ai_client_result_objects(): void {
		WordPressTestState::$ai_client_supported = true;
		$usage                                   = new class() {
			public function __call( string $name, array $arguments ): array {
				unset( $arguments );

				if ( 'toArray' === $name ) {
					return [
						'promptTokens'     => 13,
						'completionTokens' => 34,
						'totalTokens'      => 47,
					];
				}

				return [];
			}
		};
		WordPressTestState::$ai_client_generate_text_result = new class( $usage ) {
			private object $usage;

			public function __construct( object $usage ) {
				$this->usage = $usage;
			}

			public function __call( string $name, array $arguments ): mixed {
				unset( $arguments );

				return match ( $name ) {
					'toText' => '{"explanation":"Use the accent color."}',
					'toArray' => [
						'id' => 'provider-result-123',
					],
					'getTokenUsage' => $this->usage,
					default => null,
				};
			}
		};

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);
		$meta   = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 47, $meta['tokenUsage']['total'] ?? null );
		$this->assertSame( 13, $meta['tokenUsage']['input'] ?? null );
		$this->assertSame( 34, $meta['tokenUsage']['output'] ?? null );
	}

	public function test_chat_records_request_and_response_summaries_for_plain_ai_client_text(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';
		$system_prompt                                      = 'WordPress Gutenberg block styling and configuration assistant.';
		$user_prompt                                        = 'Recommend a better block.';

		$result = WordPressAIClient::chat(
			$system_prompt,
			$user_prompt,
			'anthropic',
			'high',
			[
				'type'                 => 'object',
				'additionalProperties' => false,
			]
		);
		$meta   = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 'wordpress-ai-client', $meta['transport']['host'] ?? null );
		$this->assertSame( '/generate-text', $meta['transport']['path'] ?? null );
		$this->assertSame( 90, $meta['transport']['timeoutSeconds'] ?? null );
		$this->assertSame( strlen( $system_prompt ), $meta['requestSummary']['instructionsChars'] ?? null );
		$this->assertSame( strlen( $user_prompt ), $meta['requestSummary']['inputChars'] ?? null );
		$this->assertSame( 'high', $meta['requestSummary']['reasoningEffort'] ?? null );
		$this->assertIsInt( $meta['requestSummary']['bodyBytes'] ?? null );
		$this->assertGreaterThan( 0, $meta['requestSummary']['bodyBytes'] ?? 0 );
		$this->assertSame( 39, $meta['responseSummary']['bodyBytes'] ?? null );
		$this->assertIsInt( $meta['responseSummary']['processingMs'] ?? null );
	}

	public function test_chat_expands_wordpress_ai_client_http_timeout_during_generation(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 90, WordPressTestState::$last_http_request_args['timeout'] ?? null );
		$this->assertSame(
			30,
			apply_filters(
				'http_request_args',
				[ 'timeout' => 30 ],
				'https://api.openai.com/v1/responses'
			)['timeout'] ?? null
		);
	}

	public function test_chat_emits_structured_diagnostic_trace_events_when_enabled(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';
		$events = [];

		add_filter( 'flavor_agent_diagnostic_trace_enabled', '__return_false' );
		add_action(
			'flavor_agent_diagnostic_trace',
			static function ( array $entry ) use ( &$events ): void {
				$events[] = $entry;
			},
			10,
			1
		);

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.',
			'codex',
			'high',
			[
				'type'       => 'object',
				'properties' => [
					'explanation' => [ 'type' => 'string' ],
				],
			]
		);

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertNotEmpty( $events );
		$this->assertSame(
			[
				'ai.chat.start',
				'ai.chat.request_ready',
				'ai.chat.response_ready',
				'ai.chat.finish',
			],
			array_column( $events, 'event' )
		);

		$trace_id = $events[0]['traceId'] ?? '';
		$this->assertIsString( $trace_id );
		$this->assertNotSame( '', $trace_id );

		foreach ( $events as $event ) {
			$this->assertSame( $trace_id, $event['traceId'] ?? null );
			$this->assertSame( 'wordpress-ai-client', $event['surface'] ?? null );
			$this->assertIsArray( $event['runtime'] ?? null );
		}

		$this->assertSame( 'codex', $events[0]['context']['provider'] ?? null );
		$this->assertSame( 'high', $events[0]['context']['reasoningEffort'] ?? null );
		$this->assertSame( 90, $events[0]['context']['timeoutSeconds'] ?? null );
		$this->assertTrue( $events[0]['context']['hasSchema'] ?? false );
		$this->assertSame( 39, $events[2]['context']['textBytes'] ?? null );
		$this->assertSame( 'success', $events[3]['context']['outcome'] ?? null );
	}

	public function test_chat_emits_trace_events_when_observer_is_attached(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

		$events = [];
		add_action(
			'flavor_agent_diagnostic_trace',
			static function ( array $entry ) use ( &$events ): void {
				$events[] = $entry['event'] ?? '';
			}
		);

		WordPressAIClient::chat( 'sys', 'user' );

		$this->assertSame(
			[ 'ai.chat.start', 'ai.chat.request_ready', 'ai.chat.response_ready', 'ai.chat.finish' ],
			$events
		);
	}

	public function test_chat_skips_trace_lifecycle_when_no_observer_is_attached(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"OK."}';

		WordPressAIClient::chat( 'sys', 'user' );

		$this->assertSame(
			0,
			WordPressTestState::$do_action_counts['flavor_agent_diagnostic_trace'] ?? 0,
			'RequestTrace::emit should not fire when no observer is attached.'
		);
	}

	public function test_chat_allows_wordpress_ai_client_timeout_filter_override(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = '{"explanation":"Use the accent color."}';

		add_filter(
			'flavor_agent_wordpress_ai_client_request_timeout',
			static fn ( int $timeout ): int => 45,
			10,
			3
		);

		$result = WordPressAIClient::chat(
			'WordPress Gutenberg block styling and configuration assistant.',
			'Recommend a better block.'
		);

		$meta = \FlavorAgent\OpenAI\Provider::active_chat_request_meta();

		$this->assertSame( '{"explanation":"Use the accent color."}', $result );
		$this->assertSame( 45, WordPressTestState::$last_http_request_args['timeout'] ?? null );
		$this->assertSame( 45, $meta['transport']['timeoutSeconds'] ?? null );
	}

	public function test_chat_normalizes_common_usage_and_provider_request_metadata(): void {
		WordPressTestState::$ai_client_supported            = true;
		WordPressTestState::$ai_client_generate_text_result = [
			'text'              => '{"explanation":"Use the accent color."}',
			'usage'             => [
				'total_tokens'  => 42,
				'input_tokens'  => 12,
				'output_tokens' => 30,
			],
			'providerRequestId' => 'anthropic-request-123',
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
		$this->assertSame( 'anthropic-request-123', $meta['responseSummary']['providerRequestId'] ?? null );
	}

	private static function count_schema_unions( array $schema ): int {
		$count = 0;

		if ( isset( $schema['type'] ) && is_array( $schema['type'] ) ) {
			++$count;
		}

		if ( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
			++$count;
		}

		foreach ( [ 'properties', 'patternProperties', 'definitions', '$defs' ] as $collection_key ) {
			if ( ! isset( $schema[ $collection_key ] ) || ! is_array( $schema[ $collection_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $collection_key ] as $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$count += self::count_schema_unions( $child_schema );
				}
			}
		}

		foreach ( [ 'items', 'contains', 'additionalProperties', 'propertyNames', 'not' ] as $schema_key ) {
			if ( isset( $schema[ $schema_key ] ) && is_array( $schema[ $schema_key ] ) ) {
				$count += self::count_schema_unions( $schema[ $schema_key ] );
			}
		}

		foreach ( [ 'anyOf', 'oneOf', 'allOf', 'prefixItems' ] as $schema_list_key ) {
			if ( ! isset( $schema[ $schema_list_key ] ) || ! is_array( $schema[ $schema_list_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $schema_list_key ] as $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$count += self::count_schema_unions( $child_schema );
				}
			}
		}

		return $count;
	}
}
