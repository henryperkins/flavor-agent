<?php

declare(strict_types=1);

namespace FlavorAgent\LLM;

use FlavorAgent\OpenAI\Provider;
use WordPress\AI_Client\AI_Client;

final class WordPressAIClient {

	private const SETUP_MESSAGE = 'Configure a text-generation provider in Settings > Connectors to enable block recommendations.';
	private const REASONING_EFFORTS = [ 'low', 'medium', 'high', 'xhigh' ];

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

	public static function chat( string $system_prompt, string $user_prompt, ?string $provider = null, ?string $reasoning_effort = null ): string|\WP_Error {
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

		$prompt = self::apply_system_instruction( $prompt, $system_prompt );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$prompt = self::apply_reasoning_effort( $prompt, $reasoning_effort );

		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

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

		$started_at = microtime( true );
		$result = self::call_prompt_method( $prompt, 'generate_text' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = self::normalize_generated_text_result( $result, $started_at );

		if ( '' === $parsed['text'] ) {
			return new \WP_Error(
				'empty_response',
				'The WordPress AI client returned an empty response.',
				[ 'status' => 502 ]
			);
		}

		Provider::record_runtime_chat_metrics( $parsed['metrics'] );

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

			try {
				$updated_prompt = $prompt->{$method}( ...$candidate['arguments'] );
			} catch ( \Throwable $throwable ) {
				continue;
			}

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

		return $prompt;
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
	 * @return array{text: string, metrics: array<string, mixed>|null}
	 */
	private static function normalize_generated_text_result( mixed $result, float $started_at ): array {
		$metrics = [
			'latencyMs' => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
		];

		if ( is_string( $result ) ) {
			return [
				'text'    => trim( $result ),
				'metrics' => $metrics,
			];
		}

		if ( is_object( $result ) ) {
			$result = get_object_vars( $result );
		}

		if ( ! is_array( $result ) ) {
			return [
				'text'    => '',
				'metrics' => $metrics,
			];
		}

		$text = isset( $result['text'] ) && is_string( $result['text'] )
			? trim( $result['text'] )
			: '';

		if ( is_array( $result['tokenUsage'] ?? null ) ) {
			$token_usage = [];

			$total = self::normalize_metric_int( $result['tokenUsage']['total'] ?? null );
			$input = self::normalize_metric_int( $result['tokenUsage']['input'] ?? null );
			$output = self::normalize_metric_int( $result['tokenUsage']['output'] ?? null );

			if ( null !== $total ) {
				$token_usage['total'] = $total;
			}

			if ( null !== $input ) {
				$token_usage['input'] = $input;
			}

			if ( null !== $output ) {
				$token_usage['output'] = $output;
			}

			if ( [] !== $token_usage ) {
				$metrics['tokenUsage'] = $token_usage;
			}
		}

		$latency_ms = self::normalize_metric_int( $result['latencyMs'] ?? null );
		if ( null !== $latency_ms ) {
			$metrics['latencyMs'] = $latency_ms;
		}

		return [
			'text'    => $text,
			'metrics' => $metrics,
		];
	}

	private static function normalize_metric_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( is_float( $value ) ) {
			$normalized = (int) round( $value );

			return $normalized >= 0 ? $normalized : null;
		}

		if ( is_string( $value ) && '' !== trim( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
			$normalized = (int) $value;

			return $normalized >= 0 ? $normalized : null;
		}

		return null;
	}
}
