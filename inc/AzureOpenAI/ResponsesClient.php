<?php

declare(strict_types=1);

namespace FlavorAgent\AzureOpenAI;

use FlavorAgent\LLM\WordPressAIClient;
use FlavorAgent\OpenAI\Provider;

final class ResponsesClient extends BaseHttpClient {

	private const DEFAULT_REASONING_EFFORT = 'medium';
	private const REQUEST_TIMEOUT          = 180;

	public static function validate_configuration(
		?string $endpoint = null,
		?string $api_key = null,
		?string $deployment = null,
		?string $provider = null
	): true|\WP_Error {
		$provider = Provider::normalize_provider( $provider ?? Provider::get() );

		if ( Provider::is_connector( $provider ) ) {
			if ( WordPressAIClient::is_supported( $provider ) ) {
				return true;
			}

			return new \WP_Error(
				'responses_validation_error',
				sprintf(
					'%s is not currently available through Settings > Connectors.',
					Provider::label( $provider )
				),
				[ 'status' => 400 ]
			);
		}

		$config = Provider::chat_configuration(
			$provider,
			Provider::is_native( $provider )
				? [
					'flavor_agent_openai_native_api_key' => (string) ( $api_key ?? get_option( 'flavor_agent_openai_native_api_key', '' ) ),
					'flavor_agent_openai_native_chat_model' => (string) ( $deployment ?? get_option( 'flavor_agent_openai_native_chat_model', '' ) ),
				]
				: [
					'flavor_agent_azure_openai_endpoint' => (string) ( $endpoint ?? get_option( 'flavor_agent_azure_openai_endpoint', '' ) ),
					'flavor_agent_azure_openai_key'      => (string) ( $api_key ?? get_option( 'flavor_agent_azure_openai_key', '' ) ),
					'flavor_agent_azure_chat_deployment' => (string) ( $deployment ?? get_option( 'flavor_agent_azure_chat_deployment', '' ) ),
				]
		);

		return ConfigurationValidator::validate(
			$config['url'],
			$config['headers'],
			$config['model'],
			[
				'input'             => 'validation',
				'max_output_tokens' => 16,
				'reasoning'         => self::reasoning_options( null, $provider ),
			],
			'responses_validation_error',
			$config['label'],
			'responses'
		);
	}

	/**
	 * Send a ranking/instruction request to the Azure OpenAI Responses API.
	 *
	 * @return string|\WP_Error The assistant's text response.
	 */
	public static function rank( string $instructions, string $input, ?string $reasoning_effort = null ): string|\WP_Error {
		Provider::record_runtime_chat_metrics( null );
		Provider::record_runtime_chat_diagnostics( null );

		$config                    = Provider::chat_configuration();
		$provider                  = is_string( $config['provider'] ?? null ) ? $config['provider'] : null;
		$resolved_reasoning_effort = self::resolve_reasoning_effort( $reasoning_effort, $provider );

		if ( Provider::is_connector( $config['provider'] ) || 'wordpress_ai_client' === $config['provider'] ) {
			return WordPressAIClient::chat(
				$instructions,
				$input,
				Provider::is_connector( $config['provider'] ) ? $config['provider'] : null,
				$resolved_reasoning_effort
			);
		}

		if ( ! $config['configured'] ) {
			return new \WP_Error(
				'missing_credentials',
				sprintf(
					'%s chat credentials are not configured. Go to Settings > Flavor Agent.',
					Provider::label( $config['provider'] )
				),
				[ 'status' => 400 ]
			);
		}

		$body = wp_json_encode(
			[
				'model'        => $config['model'],
				'instructions' => $instructions,
				'input'        => $input,
				'reasoning'    => self::reasoning_options( $resolved_reasoning_effort, $provider ),
			]
		);

		return self::request( $config['url'], $config['headers'], $body, $config['label'] );
	}

	/**
	 * @return array{effort: string}
	 */
	private static function reasoning_options( ?string $reasoning_effort = null, ?string $provider = null ): array {
		return [
			'effort' => self::resolve_reasoning_effort( $reasoning_effort, $provider ),
		];
	}

	private static function resolve_reasoning_effort( ?string $reasoning_effort, ?string $provider = null ): string {
		if ( is_string( $reasoning_effort ) && $reasoning_effort !== '' ) {
			return self::sanitize_reasoning_effort( $reasoning_effort ) ?? self::DEFAULT_REASONING_EFFORT;
		}

		if (
			Provider::is_azure( $provider )
			|| Provider::is_connector( $provider )
			|| 'wordpress_ai_client' === $provider
		) {
			return self::saved_reasoning_effort();
		}

		return self::DEFAULT_REASONING_EFFORT;
	}

	private static function saved_reasoning_effort(): string {
		$candidate = self::sanitize_reasoning_effort(
			(string) get_option(
				'flavor_agent_azure_reasoning_effort',
				self::DEFAULT_REASONING_EFFORT
			)
		);

		return $candidate ?? self::DEFAULT_REASONING_EFFORT;
	}

	private static function sanitize_reasoning_effort( ?string $reasoning_effort ): ?string {
		if ( ! is_string( $reasoning_effort ) || $reasoning_effort === '' ) {
			return null;
		}

		$candidate = sanitize_key( $reasoning_effort );

		return in_array( $candidate, [ 'low', 'medium', 'high', 'xhigh' ], true )
			? $candidate
			: null;
	}

	/**
	 * @return string|\WP_Error The text content from the response.
	 */
	private static function request( string $url, array $headers, string $body, string $label ): string|\WP_Error {
		$started_at = microtime( true );
		$base_diagnostics = self::build_base_diagnostics( $url, $body, self::REQUEST_TIMEOUT );
		$response = self::post_json_with_retry(
			$url,
			$headers,
			$body,
			$label,
			self::REQUEST_TIMEOUT
		);

		if ( is_wp_error( $response ) ) {
			Provider::record_runtime_chat_metrics( self::extract_response_metrics( [], $started_at ) );
			Provider::record_runtime_chat_diagnostics(
				self::build_error_diagnostics( $base_diagnostics, $response )
			);

			return self::append_request_meta_to_error( $response );
		}

		$status = $response['status'];
		$data   = $response['data'];
		$metrics = self::extract_response_metrics(
			is_array( $data ) ? $data : [],
			$started_at
		);
		$diagnostics = self::build_response_diagnostics(
			$base_diagnostics,
			$status,
			$response['headers'],
			(int) $response['body_bytes'],
			is_array( $data ) ? $data : []
		);

		if ( $status !== 200 ) {
			Provider::record_runtime_chat_metrics( $metrics );
			Provider::record_runtime_chat_diagnostics( $diagnostics );
			$msg = is_array( $data ) ? ( $data['error']['message'] ?? "{$label} returned HTTP {$status}" ) : "{$label} returned HTTP {$status}";
			return self::append_request_meta_to_error(
				new \WP_Error(
					'responses_error',
					$msg,
					[
						'status'      => 502,
						'http_status' => $status,
					]
				)
			);
		}

		if ( JSON_ERROR_NONE !== $response['json_error'] ) {
			Provider::record_runtime_chat_metrics( $metrics );
			Provider::record_runtime_chat_diagnostics( $diagnostics );
			return self::append_request_meta_to_error(
				new \WP_Error(
					'responses_parse_error',
					'Failed to parse Responses API response.',
					[
						'status'      => 502,
						'http_status' => $status,
					]
				)
			);
		}

		$text = ConfigurationValidator::extract_response_text( is_array( $data ) ? $data : [] );

		if ( empty( $text ) ) {
			Provider::record_runtime_chat_metrics( $metrics );
			Provider::record_runtime_chat_diagnostics( $diagnostics );
			return self::append_request_meta_to_error(
				new \WP_Error(
					'empty_response',
					"{$label} returned no text.",
					[
						'status'      => 502,
						'http_status' => $status,
					]
				)
			);
		}

		Provider::record_runtime_chat_metrics( $metrics );
		Provider::record_runtime_chat_diagnostics( $diagnostics );

		return $text;
	}

	/**
	 * @return array{
	 *   transport: array<string, mixed>,
	 *   requestSummary: array<string, mixed>
	 * }
	 */
	private static function build_base_diagnostics( string $url, string $body, int $timeout ): array {
		$decoded_body = json_decode( $body, true );
		$payload      = is_array( $decoded_body ) ? $decoded_body : [];
		$host         = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path         = (string) wp_parse_url( $url, PHP_URL_PATH );
		$request_summary = [
			'bodyBytes' => strlen( $body ),
		];

		if ( [] !== $payload ) {
			$request_summary['topLevelKeys'] = array_values(
				array_filter(
					array_keys( $payload ),
					static fn ( $key ): bool => is_string( $key ) && '' !== $key
				)
			);
		}

		$instructions_chars = self::measure_payload_value( $payload['instructions'] ?? null );
		$input_chars        = self::measure_payload_value( $payload['input'] ?? null );
		$max_output_tokens  = self::normalize_metric_int( $payload['max_output_tokens'] ?? null );
		$reasoning_effort   = trim(
			(string) (
				is_array( $payload['reasoning'] ?? null )
					? ( $payload['reasoning']['effort'] ?? '' )
					: ''
			)
		);

		if ( null !== $instructions_chars ) {
			$request_summary['instructionsChars'] = $instructions_chars;
		}

		if ( null !== $input_chars ) {
			$request_summary['inputChars'] = $input_chars;
		}

		if ( null !== $max_output_tokens ) {
			$request_summary['maxOutputTokens'] = $max_output_tokens;
		}

		if ( '' !== $reasoning_effort ) {
			$request_summary['reasoningEffort'] = $reasoning_effort;
		}

		return [
			'transport'      => [
				'method'         => 'POST',
				'host'           => '' !== $host ? $host : $url,
				'path'           => $path,
				'timeoutSeconds' => $timeout,
			],
			'requestSummary' => $request_summary,
		];
	}

	/**
	 * @param array{
	 *   transport: array<string, mixed>,
	 *   requestSummary: array<string, mixed>
	 * } $base_diagnostics
	 * @param array<string, string> $headers
	 * @param array<string, mixed> $data
	 * @return array{
	 *   transport: array<string, mixed>,
	 *   requestSummary: array<string, mixed>,
	 *   responseSummary: array<string, mixed>
	 * }
	 */
	private static function build_response_diagnostics(
		array $base_diagnostics,
		int $status,
		array $headers,
		int $body_bytes,
		array $data
	): array {
		$response_summary = [
			'httpStatus' => $status,
			'bodyBytes'  => max( 0, $body_bytes ),
		];

		$provider_request_id = self::first_header_value(
			$headers,
			[ 'apim-request-id', 'x-request-id', 'request-id', 'x-ms-request-id' ]
		);
		$processing_ms       = self::normalize_metric_int(
			self::first_header_value( $headers, [ 'openai-processing-ms', 'x-openai-processing-ms' ] )
		);
		$retry_after         = self::normalize_metric_int(
			self::first_header_value( $headers, [ 'retry-after' ] )
		);
		$region              = self::first_header_value( $headers, [ 'x-ms-region' ] );

		if ( null !== $provider_request_id ) {
			$response_summary['providerRequestId'] = $provider_request_id;
		}

		if ( null !== $processing_ms ) {
			$response_summary['processingMs'] = $processing_ms;
		}

		if ( null !== $retry_after ) {
			$response_summary['retryAfter'] = $retry_after;
		}

		if ( null !== $region ) {
			$response_summary['region'] = $region;
		}

		if ( is_array( $data['error'] ?? null ) && is_string( $data['error']['message'] ?? null ) ) {
			$response_summary['errorMessage'] = trim( (string) $data['error']['message'] );
		}

		return $base_diagnostics + [
			'responseSummary' => $response_summary,
		];
	}

	/**
	 * @param array{
	 *   transport: array<string, mixed>,
	 *   requestSummary: array<string, mixed>
	 * } $base_diagnostics
	 * @return array{
	 *   transport: array<string, mixed>,
	 *   requestSummary: array<string, mixed>,
	 *   responseSummary?: array<string, mixed>,
	 *   errorSummary: array<string, mixed>
	 * }
	 */
	private static function build_error_diagnostics( array $base_diagnostics, \WP_Error $error ): array {
		$error_code = $error->get_error_code();
		$error_data = $error->get_error_data( $error_code );
		$error_data = is_array( $error_data ) ? $error_data : [];
		$response_summary = [];
		$error_summary    = [
			'code'    => $error_code,
			'message' => trim( $error->get_error_message( $error_code ) ),
		];

		$http_status = self::normalize_metric_int( $error_data['http_status'] ?? $error_data['status'] ?? null );

		if ( null !== $http_status ) {
			$response_summary['httpStatus'] = $http_status;
		}

		$response_body_bytes = self::normalize_metric_int( $error_data['response_body_bytes'] ?? null );

		if ( null !== $response_body_bytes ) {
			$response_summary['bodyBytes'] = $response_body_bytes;
		}

		if ( is_string( $error_data['wrapped'] ?? null ) && '' !== trim( (string) $error_data['wrapped'] ) ) {
			$error_summary['wrappedMessage'] = trim( (string) $error_data['wrapped'] );
		}

		$response_headers = is_array( $error_data['response_headers'] ?? null )
			? $error_data['response_headers']
			: [];

		$provider_request_id = self::first_header_value(
			$response_headers,
			[ 'apim-request-id', 'x-request-id', 'request-id', 'x-ms-request-id' ]
		);
		$retry_after         = self::normalize_metric_int( $error_data['retry_after'] ?? null );

		if ( null !== $provider_request_id ) {
			$response_summary['providerRequestId'] = $provider_request_id;
		}

		if ( null !== $retry_after ) {
			$response_summary['retryAfter'] = $retry_after;
		}

		if ( [] !== $response_headers ) {
			$region = self::first_header_value( $response_headers, [ 'x-ms-region' ] );

			if ( null !== $region ) {
				$response_summary['region'] = $region;
			}
		}

		return $base_diagnostics + array_filter(
			[
				'responseSummary' => [] !== $response_summary ? $response_summary : null,
				'errorSummary'    => $error_summary,
			]
		);
	}

	private static function append_request_meta_to_error( \WP_Error $error ): \WP_Error {
		$code = $error->get_error_code();
		$data = $error->get_error_data( $code );
		$data = is_array( $data )
			? $data
			: ( null !== $data ? [ 'originalData' => $data ] : [] );

		$data['requestMeta'] = Provider::active_chat_request_meta();

		return new \WP_Error(
			$code,
			$error->get_error_message( $code ),
			$data
		);
	}

	private static function first_header_value( array $headers, array $candidates ): ?string {
		foreach ( $candidates as $candidate ) {
			$value = trim( (string) ( $headers[ strtolower( $candidate ) ] ?? '' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return null;
	}

	private static function measure_payload_value( $value ): ?int {
		if ( is_string( $value ) ) {
			return self::string_length( $value );
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );

			if ( false !== $encoded ) {
				return self::string_length( $encoded );
			}
		}

		return null;
	}

	private static function string_length( string $value ): int {
		return function_exists( 'mb_strlen' )
			? (int) mb_strlen( $value, 'UTF-8' )
			: strlen( $value );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{tokenUsage: array<string, int>, latencyMs: int}
	 */
	private static function extract_response_metrics( array $data, float $started_at ): array {
		$usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
		$token_usage = [];

		$total = self::normalize_metric_int( $usage['total_tokens'] ?? $usage['totalTokens'] ?? null );
		$input = self::normalize_metric_int( $usage['input_tokens'] ?? $usage['inputTokens'] ?? null );
		$output = self::normalize_metric_int( $usage['output_tokens'] ?? $usage['outputTokens'] ?? null );

		if ( null !== $total ) {
			$token_usage['total'] = $total;
		}

		if ( null !== $input ) {
			$token_usage['input'] = $input;
		}

		if ( null !== $output ) {
			$token_usage['output'] = $output;
		}

		return [
			'tokenUsage' => $token_usage,
			'latencyMs'  => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
		];
	}

	private static function normalize_metric_int( $value ): ?int {
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
