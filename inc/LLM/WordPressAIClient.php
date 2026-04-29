<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Support\MetricsNormalizer;
use WordPress\AI_Client\AI_Client;

final class WordPressAIClient {

	private const SETUP_MESSAGE         = 'Configure a text-generation provider in Settings > Connectors to enable block recommendations.';
	private const REASONING_EFFORTS     = [ 'low', 'medium', 'high', 'xhigh' ];
	private const ANTHROPIC_PROVIDER    = 'anthropic';
	private const ANTHROPIC_UNION_LIMIT = 16;

	public static function is_supported( ?string $provider = null ): bool {
		$prompt = self::make_prompt( 'Flavor Agent availability check.' );

		if ( is_wp_error( $prompt ) ) {
			return false;
		}

		$prompt = self::apply_provider_selection( $prompt, $provider );

		if ( is_wp_error( $prompt ) ) {
			return false;
		}

		$supported = self::call_prompt_method( $prompt, 'is_supported_for_text_generation' );

		return ! is_wp_error( $supported ) && (bool) $supported;
	}

	public static function chat(
		string $system_prompt,
		string $user_prompt,
		?string $provider = null,
		?string $reasoning_effort = null,
		?array $schema = null
	): string|\WP_Error {
		Provider::record_runtime_chat_metrics( null );
		Provider::record_runtime_chat_diagnostics( null );
		$prompt = self::make_prompt( $user_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$prompt = self::apply_provider_selection( $prompt, $provider );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$supported = self::ensure_text_generation_supported( $prompt );

		if ( is_wp_error( $supported ) ) {
			return $supported;
		}

		$prompt = self::apply_system_instruction( $prompt, $system_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$prompt = self::apply_reasoning_effort( $prompt, $reasoning_effort );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$prompt = self::apply_output_schema( $prompt, $schema, $provider );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$request_diagnostics = self::build_request_diagnostics(
			$system_prompt,
			$user_prompt,
			$provider,
			$reasoning_effort,
			$schema
		);
		$started_at          = microtime( true );
		$result              = self::call_prompt_method( $prompt, 'generate_text' );

		if ( is_wp_error( $result ) ) {
			Provider::record_runtime_chat_diagnostics(
				self::with_error_summary( $request_diagnostics, $result )
			);

			return $result;
		}

		$parsed = self::normalize_generated_text_result( $result, $started_at, $request_diagnostics );

		Provider::record_runtime_chat_metrics( $parsed['metrics'] );
		Provider::record_runtime_chat_diagnostics( $parsed['diagnostics'] );

		if ( '' === $parsed['text'] ) {
			return new \WP_Error(
				'empty_response',
				'The WordPress AI client returned an empty response.',
				[ 'status' => 502 ]
			);
		}

		return $parsed['text'];
	}

	public static function get_setup_message(): string {
		return self::SETUP_MESSAGE;
	}

	/**
	 * @return object|\WP_Error
	 */
	private static function make_prompt( string $user_prompt ): mixed {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error(
				'wp_ai_client_unavailable',
				'WordPress AI Client is unavailable. Flavor Agent requires WordPress 7.0+ with the built-in AI client.',
				[ 'status' => 500 ]
			);
		}

		$prompt = wp_ai_client_prompt( $user_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		if ( ! is_object( $prompt ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				'WordPress AI Client did not return a prompt builder.',
				[ 'status' => 500 ]
			);
		}

		return $prompt;
	}

	/**
	 * @return object|\WP_Error
	 */
	private static function apply_provider_selection( object $prompt, ?string $provider ) {
		$provider = is_string( $provider ) ? sanitize_key( $provider ) : '';

		if ( '' === $provider ) {
			return $prompt;
		}

		$updated_prompt = self::call_prompt_method( $prompt, 'using_provider', [ $provider ] );

		if ( is_wp_error( $updated_prompt ) ) {
			return $updated_prompt;
		}

		if ( ! is_object( $updated_prompt ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				'WordPress AI Client did not return a prompt builder.',
				[ 'status' => 500 ]
			);
		}

		return $updated_prompt;
	}

	/**
	 * @return object|\WP_Error
	 */
	private static function apply_system_instruction( object $prompt, string $system_prompt ): object {
		if ( ! is_callable( [ $prompt, 'using_system_instruction' ] ) ) {
			return $prompt;
		}

		$updated_prompt = self::call_prompt_method( $prompt, 'using_system_instruction', [ $system_prompt ] );

		if ( is_wp_error( $updated_prompt ) ) {
			return $updated_prompt;
		}

		if ( ! is_object( $updated_prompt ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				'WordPress AI Client did not return a prompt builder.',
				[ 'status' => 500 ]
			);
		}

		return $updated_prompt;
	}

	/**
	 * @return object|\WP_Error
	 */
	private static function apply_reasoning_effort( object $prompt, ?string $reasoning_effort ) {
		$reasoning_effort = is_string( $reasoning_effort ) ? sanitize_key( $reasoning_effort ) : '';

		if ( ! in_array( $reasoning_effort, self::REASONING_EFFORTS, true ) ) {
			return $prompt;
		}

		$candidates = [
			[
				'method'    => 'using_reasoning_effort',
				'arguments' => [ $reasoning_effort ],
			],
			[
				'method'    => 'using_reasoning',
				'arguments' => [ $reasoning_effort ],
			],
			[
				'method'    => 'using_reasoning',
				'arguments' => [
					[
						'effort' => $reasoning_effort,
					],
				],
			],
		];

		foreach ( $candidates as $candidate ) {
			$method = (string) $candidate['method'];

			if ( ! is_callable( [ $prompt, $method ] ) ) {
				continue;
			}

			$candidate_prompt = self::clone_prompt_for_optional_feature( $prompt );

			if ( null === $candidate_prompt ) {
				continue;
			}

			try {
				$updated_prompt = $candidate_prompt->{$method}( ...$candidate['arguments'] );
			} catch ( \Throwable $throwable ) {
				continue;
			}

			if ( is_wp_error( $updated_prompt ) ) {
				continue;
			}

			if ( ! is_object( $updated_prompt ) ) {
				return new \WP_Error(
					'wp_ai_client_invalid_prompt',
					'WordPress AI Client did not return a prompt builder.',
					[ 'status' => 500 ]
				);
			}

			if ( self::prompt_supports_text_generation( $updated_prompt ) ) {
				return $updated_prompt;
			}
		}

		return $prompt;
	}

	private static function apply_output_schema( object $prompt, ?array $schema, ?string $provider ): object {
		if ( null === $schema || [] === $schema ) {
			return $prompt;
		}

		$schema = self::normalize_output_schema( $schema );

		if ( self::should_skip_output_schema( $provider, $schema ) ) {
			return $prompt;
		}

		if ( ! is_callable( [ $prompt, 'as_json_response' ] ) ) {
			return $prompt;
		}

		$candidate_prompt = self::clone_prompt_for_optional_feature( $prompt );

		if ( null === $candidate_prompt ) {
			return $prompt;
		}

		$updated_prompt = self::call_prompt_method(
			$candidate_prompt,
			'as_json_response',
			[ $schema ]
		);

		if ( is_wp_error( $updated_prompt ) ) {
			return $prompt;
		}

		if ( ! is_object( $updated_prompt ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				'WordPress AI Client did not return a prompt builder from as_json_response.',
				[ 'status' => 500 ]
			);
		}

		return self::prompt_supports_text_generation( $updated_prompt ) ? $updated_prompt : $prompt;
	}

	private static function normalize_output_schema( array $schema ): array {
		$schema = self::normalize_nested_schemas( $schema );

		if (
			isset( $schema['type'] )
			&& is_array( $schema['type'] )
			&& isset( $schema['enum'] )
			&& is_array( $schema['enum'] )
		) {
			$schema = self::expand_union_enum_schema( $schema );
		}

		if ( self::schema_includes_type( $schema, 'object' ) ) {
			$schema['additionalProperties'] = false;
		}

		return $schema;
	}

	private static function normalize_nested_schemas( array $schema ): array {
		foreach ( [ 'properties', 'patternProperties', 'definitions', '$defs' ] as $collection_key ) {
			if ( ! isset( $schema[ $collection_key ] ) || ! is_array( $schema[ $collection_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $collection_key ] as $key => $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$schema[ $collection_key ][ $key ] = self::normalize_output_schema( $child_schema );
				}
			}
		}

		foreach ( [ 'items', 'contains', 'additionalProperties', 'propertyNames', 'not' ] as $schema_key ) {
			if ( isset( $schema[ $schema_key ] ) && is_array( $schema[ $schema_key ] ) ) {
				$schema[ $schema_key ] = self::normalize_schema_or_schema_list( $schema[ $schema_key ] );
			}
		}

		foreach ( [ 'anyOf', 'oneOf', 'allOf', 'prefixItems' ] as $schema_list_key ) {
			if ( ! isset( $schema[ $schema_list_key ] ) || ! is_array( $schema[ $schema_list_key ] ) ) {
				continue;
			}

			foreach ( $schema[ $schema_list_key ] as $key => $child_schema ) {
				if ( is_array( $child_schema ) ) {
					$schema[ $schema_list_key ][ $key ] = self::normalize_output_schema( $child_schema );
				}
			}
		}

		return $schema;
	}

	private static function normalize_schema_or_schema_list( array $schema ): array {
		if ( ! self::is_list_array( $schema ) ) {
			return self::normalize_output_schema( $schema );
		}

		foreach ( $schema as $key => $child_schema ) {
			if ( is_array( $child_schema ) ) {
				$schema[ $key ] = self::normalize_output_schema( $child_schema );
			}
		}

		return $schema;
	}

	private static function expand_union_enum_schema( array $schema ): array {
		$types = [];

		foreach ( $schema['type'] as $type ) {
			if ( is_string( $type ) && ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		if ( [] === $types ) {
			return $schema;
		}

		$normalized = $schema;
		$branches   = [];

		foreach ( $types as $type ) {
			$enum = [];

			foreach ( $normalized['enum'] as $value ) {
				if ( self::schema_value_matches_type( $value, $type ) ) {
					$enum[] = $value;
				}
			}

			if ( [] !== $enum ) {
				$branch = [
					'type' => $type,
					'enum' => $enum,
				];

				if ( 'object' === $type ) {
					$branch['additionalProperties'] = false;
				}

				$branches[] = $branch;
			}
		}

		if ( [] === $branches ) {
			return $schema;
		}

		unset( $normalized['type'], $normalized['enum'] );
		$normalized['anyOf'] = $branches;

		return $normalized;
	}

	private static function schema_includes_type( array $schema, string $type ): bool {
		$schema_type = $schema['type'] ?? null;

		if ( is_string( $schema_type ) ) {
			return $type === $schema_type;
		}

		return is_array( $schema_type ) && in_array( $type, $schema_type, true );
	}

	private static function should_skip_output_schema( ?string $provider, array $schema ): bool {
		$provider = is_string( $provider ) ? sanitize_key( $provider ) : '';

		return self::ANTHROPIC_PROVIDER === $provider
			&& self::count_schema_unions( $schema ) > self::ANTHROPIC_UNION_LIMIT;
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

	private static function schema_value_matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'null' => null === $value,
			'string' => is_string( $value ),
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'array' => is_array( $value ) && self::is_list_array( $value ),
			'object' => is_array( $value ) && ! self::is_list_array( $value ),
			default => true,
		};
	}

	private static function is_list_array( array $value ): bool {
		if ( [] === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function ensure_text_generation_supported( object $prompt ): bool|\WP_Error {
		$supported = self::call_prompt_method( $prompt, 'is_supported_for_text_generation' );

		if ( is_wp_error( $supported ) ) {
			return $supported;
		}

		if ( ! $supported ) {
			return new \WP_Error(
				'missing_text_generation_provider',
				self::SETUP_MESSAGE,
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	private static function prompt_supports_text_generation( object $prompt ): bool {
		$supported = self::call_prompt_method( $prompt, 'is_supported_for_text_generation' );

		return ! is_wp_error( $supported ) && (bool) $supported;
	}

	private static function clone_prompt_for_optional_feature( object $prompt ): ?object {
		try {
			return clone $prompt;
		} catch ( \Throwable $throwable ) {
			return null;
		}
	}

	private static function call_prompt_method( object $prompt, string $method, array $arguments = [] ): mixed {
		if ( ! is_callable( [ $prompt, $method ] ) ) {
			return new \WP_Error(
				'wp_ai_client_invalid_prompt',
				sprintf( 'WordPress AI Client prompt builder does not support %s.', $method ),
				[ 'status' => 500 ]
			);
		}

		try {
			return $prompt->{$method}( ...$arguments );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error(
				'wp_ai_client_request_failed',
				$throwable->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * @param string|array<string, mixed>|object $result
	 * @param array<string, mixed> $request_diagnostics
	 * @return array{text: string, metrics: array<string, mixed>|null, diagnostics: array<string, mixed>|null}
	 */
	private static function normalize_generated_text_result( mixed $result, float $started_at, array $request_diagnostics ): array {
		$elapsed_ms = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
		$metrics    = [
			'latencyMs' => $elapsed_ms,
		];

		if ( is_string( $result ) ) {
			return [
				'text'        => trim( $result ),
				'metrics'     => $metrics,
				'diagnostics' => self::with_response_summary(
					$request_diagnostics,
					$result,
					$elapsed_ms
				),
			];
		}

		if ( is_object( $result ) ) {
			$result = get_object_vars( $result );
		}

		if ( ! is_array( $result ) ) {
			return [
				'text'        => '',
				'metrics'     => $metrics,
				'diagnostics' => self::with_response_summary(
					$request_diagnostics,
					'',
					$elapsed_ms
				),
			];
		}

		$text        = self::extract_generated_text( $result );
		$token_usage = self::normalize_token_usage( $result );

		if ( [] !== $token_usage ) {
			$metrics['tokenUsage'] = $token_usage;
		}

		$latency_ms = MetricsNormalizer::normalize_metric_int( $result['latencyMs'] ?? null );
		if ( null !== $latency_ms ) {
			$metrics['latencyMs'] = $latency_ms;
		}

		return [
			'text'        => $text,
			'metrics'     => $metrics,
			'diagnostics' => self::with_response_summary(
				$request_diagnostics,
				$text,
				(int) $metrics['latencyMs'],
				self::extract_provider_request_id( $result )
			),
		];
	}

	/**
	 * @param array<string, mixed>|null $schema
	 * @return array<string, mixed>
	 */
	private static function build_request_diagnostics(
		string $system_prompt,
		string $user_prompt,
		?string $provider,
		?string $reasoning_effort,
		?array $schema
	): array {
		$request_payload  = [
			'provider'     => is_string( $provider ) ? sanitize_key( $provider ) : '',
			'instructions' => $system_prompt,
			'input'        => $user_prompt,
		];
		$reasoning_effort = self::normalize_reasoning_effort_value( $reasoning_effort );

		if ( null !== $reasoning_effort ) {
			$request_payload['reasoning'] = [ 'effort' => $reasoning_effort ];
		}

		if ( is_array( $schema ) && [] !== $schema ) {
			$request_payload['text'] = [
				'format' => [
					'type'   => 'json_schema',
					'schema' => $schema,
				],
			];
		}

		return [
			'transport'      => [
				'host' => 'wordpress-ai-client',
				'path' => '/generate-text',
			],
			'requestSummary' => array_filter(
				[
					'bodyBytes'         => self::json_byte_length( $request_payload ),
					'instructionsChars' => strlen( $system_prompt ),
					'inputChars'        => strlen( $user_prompt ),
					'reasoningEffort'   => $reasoning_effort,
				],
				static fn ( mixed $value ): bool => null !== $value && '' !== $value
			),
		];
	}

	/**
	 * @param array<string, mixed> $diagnostics
	 * @return array<string, mixed>
	 */
	private static function with_response_summary(
		array $diagnostics,
		string $text,
		int $processing_ms,
		string $provider_request_id = ''
	): array {
		$response_summary = [
			'bodyBytes'    => strlen( $text ),
			'processingMs' => max( 0, $processing_ms ),
		];

		if ( '' !== $provider_request_id ) {
			$response_summary['providerRequestId'] = $provider_request_id;
		}

		$diagnostics['responseSummary'] = $response_summary;

		return $diagnostics;
	}

	/**
	 * @param array<string, mixed> $diagnostics
	 * @return array<string, mixed>
	 */
	private static function with_error_summary( array $diagnostics, \WP_Error $error ): array {
		$diagnostics['errorSummary'] = [
			'code'           => $error->get_error_code(),
			'wrappedMessage' => $error->get_error_message(),
		];

		return $diagnostics;
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function extract_generated_text( array $result ): string {
		foreach ( [ 'text', 'output_text', 'outputText', 'content' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				return trim( $result[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, int>
	 */
	private static function normalize_token_usage( array $result ): array {
		$usage = is_array( $result['tokenUsage'] ?? null ) ? $result['tokenUsage'] : [];

		if ( [] === $usage && is_array( $result['usage'] ?? null ) ) {
			$usage = $result['usage'];
		}

		$total  = self::first_metric_int( $usage, [ 'total', 'totalTokens', 'total_tokens' ] );
		$input  = self::first_metric_int( $usage, [ 'input', 'inputTokens', 'input_tokens', 'prompt_tokens' ] );
		$output = self::first_metric_int( $usage, [ 'output', 'outputTokens', 'output_tokens', 'completion_tokens' ] );

		return array_filter(
			[
				'total'  => $total,
				'input'  => $input,
				'output' => $output,
			],
			static fn ( mixed $value ): bool => null !== $value
		);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param string[] $keys
	 */
	private static function first_metric_int( array $values, array $keys ): ?int {
		foreach ( $keys as $key ) {
			$normalized = MetricsNormalizer::normalize_metric_int( $values[ $key ] ?? null );

			if ( null !== $normalized ) {
				return $normalized;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function extract_provider_request_id( array $result ): string {
		foreach ( [ 'providerRequestId', 'provider_request_id', 'requestId', 'request_id' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) ) {
				$value = trim( (string) $result[ $key ] );

				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	private static function normalize_reasoning_effort_value( ?string $reasoning_effort ): ?string {
		$reasoning_effort = is_string( $reasoning_effort ) ? sanitize_key( $reasoning_effort ) : '';

		return in_array( $reasoning_effort, self::REASONING_EFFORTS, true )
			? $reasoning_effort
			: null;
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private static function json_byte_length( array $value ): int {
		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? strlen( $encoded ) : 0;
	}
}
