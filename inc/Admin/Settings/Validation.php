<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Validation {

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $cloudflare_validation_state = null;

	private static bool $cloudflare_validation_error_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $azure_validation_state = null;

	private static bool $azure_validation_error_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $native_openai_validation_state = null;

	private static bool $native_openai_validation_error_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $qdrant_validation_state = null;

	private static bool $qdrant_validation_error_reported = false;

	/**
	 * @var array<string, array{request_fingerprint: string, values: array<string, string>}>
	 */
	private static array $submission_value_cache = [];

	/**
	 * @var array{raw_post: array<string, mixed>, fingerprint: string}|null
	 */
	private static ?array $submission_request_fingerprint = null;

	public static function reset(): void {
		self::$azure_validation_state                 = null;
		self::$azure_validation_error_reported        = false;
		self::$native_openai_validation_state         = null;
		self::$native_openai_validation_error_reported = false;
		self::$qdrant_validation_state                = null;
		self::$qdrant_validation_error_reported       = false;
		self::$cloudflare_validation_state            = null;
		self::$cloudflare_validation_error_reported   = false;
		self::$submission_value_cache                 = [];
		self::$submission_request_fingerprint         = null;
	}

	public static function sanitize_grounding_result_count( mixed $value ): int {
		$count = max( 1, min( 8, (int) $value ) );
		Feedback::mark_section_changed_by_option( 'flavor_agent_cloudflare_ai_search_max_results', $count );

		return $count;
	}

	public static function sanitize_pattern_recommendation_threshold( mixed $value ): float {
		$threshold = (float) $value;

		$threshold = max( 0.0, min( 1.0, round( $threshold, 2 ) ) );
		Feedback::mark_section_changed_by_option( 'flavor_agent_pattern_recommendation_threshold', $threshold );

		return $threshold;
	}

	public static function sanitize_pattern_max_recommendations( mixed $value ): int {
		$count = max( 1, min( Config::PATTERN_MAX_RECOMMENDATIONS_LIMIT, (int) $value ) );
		Feedback::mark_section_changed_by_option( 'flavor_agent_pattern_max_recommendations', $count );

		return $count;
	}

	public static function sanitize_azure_reasoning_effort( mixed $value ): string {
		$effort = sanitize_key( (string) $value );
		$effort = in_array( $effort, [ 'low', 'medium', 'high', 'xhigh' ], true ) ? $effort : 'medium';
		Feedback::mark_section_changed_by_option( 'flavor_agent_azure_reasoning_effort', $effort );

		return $effort;
	}

	public static function sanitize_openai_provider( mixed $value ): string {
		$provider = Provider::normalize_provider( (string) $value );
		Feedback::mark_section_changed_by_option( Provider::OPTION_NAME, $provider );

		return $provider;
	}

	public static function sanitize_azure_openai_endpoint( mixed $value ): string {
		return self::sanitize_azure_url_option(
			$value,
			'flavor_agent_azure_openai_endpoint'
		);
	}

	public static function sanitize_azure_openai_key( mixed $value ): string {
		return self::sanitize_azure_text_option(
			$value,
			'flavor_agent_azure_openai_key'
		);
	}

	public static function sanitize_azure_embedding_deployment( mixed $value ): string {
		return self::sanitize_azure_text_option(
			$value,
			'flavor_agent_azure_embedding_deployment'
		);
	}

	public static function sanitize_azure_chat_deployment( mixed $value ): string {
		return self::sanitize_azure_text_option(
			$value,
			'flavor_agent_azure_chat_deployment'
		);
	}

	public static function sanitize_openai_native_api_key( mixed $value ): string {
		return self::sanitize_openai_native_text_option(
			$value,
			'flavor_agent_openai_native_api_key'
		);
	}

	public static function sanitize_openai_native_embedding_model( mixed $value ): string {
		return self::sanitize_openai_native_text_option(
			$value,
			'flavor_agent_openai_native_embedding_model'
		);
	}

	public static function sanitize_openai_native_chat_model( mixed $value ): string {
		return self::sanitize_openai_native_text_option(
			$value,
			'flavor_agent_openai_native_chat_model'
		);
	}

	public static function sanitize_qdrant_url( mixed $value ): string {
		$sanitized_value = Utils::sanitize_url_value( $value );
		Feedback::mark_section_changed_by_option( 'flavor_agent_qdrant_url', $sanitized_value );
		$resolved_values = self::resolve_qdrant_submission_values(
			[
				'flavor_agent_qdrant_url' => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_qdrant_validation_error( $resolved_values );

			return (string) get_option( 'flavor_agent_qdrant_url', '' );
		}

		return $resolved_values['flavor_agent_qdrant_url'] ?? $sanitized_value;
	}

	public static function sanitize_qdrant_key( mixed $value ): string {
		$sanitized_value = sanitize_text_field( $value );
		Feedback::mark_section_changed_by_option( 'flavor_agent_qdrant_key', $sanitized_value );
		$resolved_values = self::resolve_qdrant_submission_values(
			[
				'flavor_agent_qdrant_key' => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_qdrant_validation_error( $resolved_values );

			return (string) get_option( 'flavor_agent_qdrant_key', '' );
		}

		return $resolved_values['flavor_agent_qdrant_key'] ?? $sanitized_value;
	}

	public static function sanitize_cloudflare_account_id( mixed $value ): string {
		return self::sanitize_cloudflare_text_option(
			$value,
			'flavor_agent_cloudflare_ai_search_account_id'
		);
	}

	public static function sanitize_cloudflare_instance_id( mixed $value ): string {
		return self::sanitize_cloudflare_text_option(
			$value,
			'flavor_agent_cloudflare_ai_search_instance_id'
		);
	}

	public static function sanitize_cloudflare_api_token( mixed $value ): string {
		return self::sanitize_cloudflare_text_option(
			$value,
			'flavor_agent_cloudflare_ai_search_api_token'
		);
	}

	public static function sanitize_guideline_site( mixed $value ): string {
		return self::sanitize_guideline_text_option( $value, Guidelines::OPTION_SITE );
	}

	public static function sanitize_guideline_copy( mixed $value ): string {
		return self::sanitize_guideline_text_option( $value, Guidelines::OPTION_COPY );
	}

	public static function sanitize_guideline_images( mixed $value ): string {
		return self::sanitize_guideline_text_option( $value, Guidelines::OPTION_IMAGES );
	}

	public static function sanitize_guideline_additional( mixed $value ): string {
		return self::sanitize_guideline_text_option( $value, Guidelines::OPTION_ADDITIONAL );
	}

	/**
	 * @return array<string, string>
	 */
	public static function sanitize_guideline_blocks( mixed $value ): array {
		$sanitized_value = Guidelines::sanitize_block_guidelines( $value );
		Feedback::mark_section_changed_by_option( Guidelines::OPTION_BLOCKS, $sanitized_value );

		return $sanitized_value;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	public static function resolve_azure_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_azure_values();
		$values         = self::get_cached_submission_values(
			'azure',
			static function () use ( $current_values ): array {
				return [
					'flavor_agent_azure_openai_endpoint'      => self::read_posted_url_value(
						'flavor_agent_azure_openai_endpoint',
						$current_values['flavor_agent_azure_openai_endpoint']
					),
					'flavor_agent_azure_openai_key'           => self::read_posted_text_value(
						'flavor_agent_azure_openai_key',
						$current_values['flavor_agent_azure_openai_key']
					),
					'flavor_agent_azure_embedding_deployment' => self::read_posted_text_value(
						'flavor_agent_azure_embedding_deployment',
						$current_values['flavor_agent_azure_embedding_deployment']
					),
					'flavor_agent_azure_chat_deployment'      => self::read_posted_text_value(
						'flavor_agent_azure_chat_deployment',
						$current_values['flavor_agent_azure_chat_deployment']
					),
				];
			}
		);

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = 'flavor_agent_azure_openai_endpoint' === $option_name
				? Utils::sanitize_url_value( $override_value )
				: sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_submission() ) {
			return $values;
		}

		if ( self::get_submitted_openai_provider() !== Provider::AZURE ) {
			return $values;
		}

		if (
			'' === $values['flavor_agent_azure_openai_endpoint'] ||
			'' === $values['flavor_agent_azure_openai_key'] ||
			'' === $values['flavor_agent_azure_embedding_deployment'] ||
			'' === $values['flavor_agent_azure_chat_deployment']
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$azure_validation_state ) &&
			( self::$azure_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$azure_validation_state['error'] instanceof \WP_Error
				? self::$azure_validation_state['error']
				: self::$azure_validation_state['values'];
		}

		$validation = EmbeddingClient::validate_configuration(
			$values['flavor_agent_azure_openai_endpoint'],
			$values['flavor_agent_azure_openai_key'],
			$values['flavor_agent_azure_embedding_deployment'],
			Provider::AZURE
		);

		if ( ! is_wp_error( $validation ) ) {
			$validation = ResponsesClient::validate_configuration(
				$values['flavor_agent_azure_openai_endpoint'],
				$values['flavor_agent_azure_openai_key'],
				$values['flavor_agent_azure_chat_deployment'],
				Provider::AZURE
			);
		}

		self::$azure_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		return is_wp_error( $validation ) ? $validation : $values;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	public static function resolve_openai_native_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_openai_native_values();
		$values         = self::get_cached_submission_values(
			'openai_native',
			static function () use ( $current_values ): array {
				return [
					'flavor_agent_openai_native_api_key'         => self::read_posted_text_value(
						'flavor_agent_openai_native_api_key',
						$current_values['flavor_agent_openai_native_api_key']
					),
					'flavor_agent_openai_native_embedding_model' => self::read_posted_text_value(
						'flavor_agent_openai_native_embedding_model',
						$current_values['flavor_agent_openai_native_embedding_model']
					),
					'flavor_agent_openai_native_chat_model'      => self::read_posted_text_value(
						'flavor_agent_openai_native_chat_model',
						$current_values['flavor_agent_openai_native_chat_model']
					),
				];
			}
		);

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = sanitize_text_field( $override_value );
		}

		$current_effective_api_key = Provider::native_effective_api_key( $current_values );
		$effective_api_key         = Provider::native_effective_api_key( $values );

		if ( ! self::should_validate_submission() ) {
			return $values;
		}

		if ( self::get_submitted_openai_provider() !== Provider::NATIVE ) {
			return $values;
		}

		if (
			'' === $effective_api_key ||
			'' === $values['flavor_agent_openai_native_embedding_model'] ||
			'' === $values['flavor_agent_openai_native_chat_model']
		) {
			return $values;
		}

		$comparison_values                                       = $values;
		$comparison_values['flavor_agent_openai_native_api_key'] = $effective_api_key;

		$current_comparison_values                                       = $current_values;
		$current_comparison_values['flavor_agent_openai_native_api_key'] = $current_effective_api_key;

		if ( ! self::values_require_validation( $comparison_values, $current_comparison_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $comparison_values );

		if (
			is_array( self::$native_openai_validation_state ) &&
			( self::$native_openai_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$native_openai_validation_state['error'] instanceof \WP_Error
				? self::$native_openai_validation_state['error']
				: self::$native_openai_validation_state['values'];
		}

		$validation = EmbeddingClient::validate_configuration(
			null,
			$effective_api_key,
			$values['flavor_agent_openai_native_embedding_model'],
			Provider::NATIVE
		);

		if ( ! is_wp_error( $validation ) ) {
			$validation = ResponsesClient::validate_configuration(
				null,
				$effective_api_key,
				$values['flavor_agent_openai_native_chat_model'],
				Provider::NATIVE
			);
		}

		self::$native_openai_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		return is_wp_error( $validation ) ? $validation : $values;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	public static function resolve_qdrant_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_qdrant_values();
		$values         = self::get_cached_submission_values(
			'qdrant',
			static function () use ( $current_values ): array {
				return [
					'flavor_agent_qdrant_url' => self::read_posted_url_value(
						'flavor_agent_qdrant_url',
						$current_values['flavor_agent_qdrant_url']
					),
					'flavor_agent_qdrant_key' => self::read_posted_text_value(
						'flavor_agent_qdrant_key',
						$current_values['flavor_agent_qdrant_key']
					),
				];
			}
		);

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = 'flavor_agent_qdrant_url' === $option_name
				? Utils::sanitize_url_value( $override_value )
				: sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_submission() ) {
			return $values;
		}

		if (
			'' === $values['flavor_agent_qdrant_url'] ||
			'' === $values['flavor_agent_qdrant_key']
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$qdrant_validation_state ) &&
			( self::$qdrant_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$qdrant_validation_state['error'] instanceof \WP_Error
				? self::$qdrant_validation_state['error']
				: self::$qdrant_validation_state['values'];
		}

		$validation = QdrantClient::validate_configuration(
			$values['flavor_agent_qdrant_url'],
			$values['flavor_agent_qdrant_key']
		);

		self::$qdrant_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		return is_wp_error( $validation ) ? $validation : $values;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	public static function resolve_cloudflare_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_cloudflare_values();
		$values         = self::get_cached_submission_values(
			'cloudflare',
			static function () use ( $current_values ): array {
				return [
					'flavor_agent_cloudflare_ai_search_account_id' => self::read_posted_cloudflare_value(
						'flavor_agent_cloudflare_ai_search_account_id',
						$current_values['flavor_agent_cloudflare_ai_search_account_id']
					),
					'flavor_agent_cloudflare_ai_search_instance_id' => self::read_posted_cloudflare_value(
						'flavor_agent_cloudflare_ai_search_instance_id',
						$current_values['flavor_agent_cloudflare_ai_search_instance_id']
					),
					'flavor_agent_cloudflare_ai_search_api_token'  => self::read_posted_cloudflare_value(
						'flavor_agent_cloudflare_ai_search_api_token',
						$current_values['flavor_agent_cloudflare_ai_search_api_token']
					),
				];
			}
		);

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_submission() ) {
			return $values;
		}

		if (
			'' === $values['flavor_agent_cloudflare_ai_search_account_id'] ||
			'' === $values['flavor_agent_cloudflare_ai_search_instance_id'] ||
			'' === $values['flavor_agent_cloudflare_ai_search_api_token']
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$cloudflare_validation_state ) &&
			( self::$cloudflare_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$cloudflare_validation_state['error'] instanceof \WP_Error
				? self::$cloudflare_validation_state['error']
				: self::$cloudflare_validation_state['values'];
		}

		$validation = AISearchClient::validate_configuration(
			$values['flavor_agent_cloudflare_ai_search_account_id'],
			$values['flavor_agent_cloudflare_ai_search_instance_id'],
			$values['flavor_agent_cloudflare_ai_search_api_token']
		);

		self::$cloudflare_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		if ( ! is_wp_error( $validation ) ) {
			AISearchClient::schedule_prewarm(
				$values['flavor_agent_cloudflare_ai_search_account_id'],
				$values['flavor_agent_cloudflare_ai_search_instance_id'],
				$values['flavor_agent_cloudflare_ai_search_api_token']
			);
		}

		return is_wp_error( $validation ) ? $validation : $values;
	}

	public static function has_saved_cloudflare_legacy_values(): bool {
		foreach ( self::get_current_cloudflare_values() as $value ) {
			if ( '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	public static function should_validate_submission(): bool {
		if ( ! self::has_valid_submission_nonce() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$option_page = $_POST['option_page'] ?? null;

		if ( ! is_string( $option_page ) ) {
			return false;
		}

		$option_page = wp_unslash( $option_page );

		return sanitize_text_field( $option_page ) === Config::OPTION_GROUP;
	}

	public static function has_valid_submission_nonce(): bool {
		if ( defined( 'FLAVOR_AGENT_TESTS_RUNNING' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is unslashed and sanitized before verification.
		$nonce = $_POST['_wpnonce'] ?? null;

		if ( ! is_string( $nonce ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $nonce ) );

		return (bool) wp_verify_nonce( $nonce, Config::OPTION_GROUP . '-options' );
	}

	private static function sanitize_azure_url_option( mixed $value, string $option_name ): string {
		$sanitized_value = Utils::sanitize_url_value( $value );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_azure_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_azure_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_azure_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_azure_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_azure_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_openai_native_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_openai_native_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_openai_native_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_cloudflare_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_cloudflare_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_cloudflare_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_guideline_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = Guidelines::sanitize_guideline_text( $value );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );

		return $sanitized_value;
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_azure_values(): array {
		return self::get_current_option_values(
			[
				'flavor_agent_azure_openai_endpoint'      => [ Utils::class, 'sanitize_url_value' ],
				'flavor_agent_azure_openai_key'           => 'sanitize_text_field',
				'flavor_agent_azure_embedding_deployment' => 'sanitize_text_field',
				'flavor_agent_azure_chat_deployment'      => 'sanitize_text_field',
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_openai_native_values(): array {
		return self::get_current_option_values(
			[
				'flavor_agent_openai_native_api_key'         => 'sanitize_text_field',
				'flavor_agent_openai_native_embedding_model' => 'sanitize_text_field',
				'flavor_agent_openai_native_chat_model'      => 'sanitize_text_field',
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_qdrant_values(): array {
		return self::get_current_option_values(
			[
				'flavor_agent_qdrant_url' => [ Utils::class, 'sanitize_url_value' ],
				'flavor_agent_qdrant_key' => 'sanitize_text_field',
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_cloudflare_values(): array {
		return self::get_current_option_values(
			[
				'flavor_agent_cloudflare_ai_search_account_id' => 'sanitize_text_field',
				'flavor_agent_cloudflare_ai_search_instance_id' => 'sanitize_text_field',
				'flavor_agent_cloudflare_ai_search_api_token'  => 'sanitize_text_field',
			]
		);
	}

	/**
	 * @param array<string, string> $values
	 * @param array<string, string> $current_values
	 */
	private static function values_require_validation( array $values, array $current_values ): bool {
		foreach ( $current_values as $option_name => $current_value ) {
			if ( ( $values[ $option_name ] ?? '' ) !== $current_value ) {
				return true;
			}
		}

		return false;
	}

	private static function get_submitted_openai_provider(): string {
		if ( ! self::has_valid_submission_nonce() ) {
			return Provider::normalize_provider( get_option( Provider::OPTION_NAME, Provider::AZURE ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed before normalization.
		$provider = $_POST[ Provider::OPTION_NAME ] ?? get_option( Provider::OPTION_NAME, Provider::AZURE );

		if ( is_string( $provider ) ) {
			$provider = wp_unslash( $provider );
		}

		return Provider::normalize_provider( is_string( $provider ) ? $provider : Provider::AZURE );
	}

	private static function read_posted_cloudflare_value( string $option_name, string $fallback ): string {
		return self::read_posted_text_value( $option_name, $fallback );
	}

	private static function read_posted_text_value( string $option_name, string $fallback ): string {
		if ( ! self::has_valid_submission_nonce() ) {
			return sanitize_text_field( $fallback );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return sanitize_text_field( $fallback );
		}

		$value = wp_unslash( $value );

		return sanitize_text_field( $value );
	}

	private static function read_posted_url_value( string $option_name, string $fallback ): string {
		if ( ! self::has_valid_submission_nonce() ) {
			return Utils::sanitize_url_value( $fallback );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return Utils::sanitize_url_value( $fallback );
		}

		$value = wp_unslash( $value );

		return Utils::sanitize_url_value( $value );
	}

	/**
	 * @param array<string, string> $values
	 */
	private static function build_validation_fingerprint( array $values ): string {
		$fingerprint_payload = wp_json_encode( $values );

		if ( ! is_string( $fingerprint_payload ) || '' === $fingerprint_payload ) {
			$fingerprint_payload = implode( '|', $values );
		}

		return md5( $fingerprint_payload );
	}

	/**
	 * @param array<string, callable> $sanitizers
	 * @return array<string, string>
	 */
	private static function get_current_option_values( array $sanitizers ): array {
		$values = [];

		foreach ( $sanitizers as $option_name => $sanitize_callback ) {
			$raw_value = (string) get_option( $option_name, '' );
			$value     = is_callable( $sanitize_callback )
				? call_user_func( $sanitize_callback, $raw_value )
				: $raw_value;

			$values[ $option_name ] = is_string( $value ) ? $value : '';
		}

		return $values;
	}

	/**
	 * @param callable(): array<string, string> $resolver
	 * @return array<string, string>
	 */
	private static function get_cached_submission_values( string $cache_key, callable $resolver ): array {
		$request_fingerprint = self::get_submission_request_fingerprint();
		$cached_values       = self::$submission_value_cache[ $cache_key ] ?? null;

		if (
			is_array( $cached_values ) &&
			( $cached_values['request_fingerprint'] ?? '' ) === $request_fingerprint
		) {
			return $cached_values['values'] ?? [];
		}

		self::$submission_value_cache[ $cache_key ] = [
			'request_fingerprint' => $request_fingerprint,
			'values'              => $resolver(),
		];

		return self::$submission_value_cache[ $cache_key ]['values'];
	}

	private static function get_submission_request_fingerprint(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only request snapshot used to invalidate in-request caches when the posted payload changes.
		$raw_post_data = is_array( $_POST ) ? $_POST : [];

		if (
			is_array( self::$submission_request_fingerprint ) &&
			( self::$submission_request_fingerprint['raw_post'] ?? [] ) === $raw_post_data
		) {
			return (string) ( self::$submission_request_fingerprint['fingerprint'] ?? '' );
		}

		$post_data = wp_unslash( $raw_post_data );
		$payload   = wp_json_encode( is_array( $post_data ) ? $post_data : [] );

		if ( ! is_string( $payload ) || '' === $payload ) {
			$payload = '';
		}

		self::$submission_request_fingerprint = [
			'raw_post'    => $raw_post_data,
			'fingerprint' => md5( $payload ),
		];

		return self::$submission_request_fingerprint['fingerprint'];
	}

	private static function report_azure_validation_error( \WP_Error $error ): void {
		if ( self::$azure_validation_error_reported ) {
			return;
		}

		Feedback::report_validation_feedback(
			Config::GROUP_CHAT,
			'flavor_agent_azure_validation',
			$error,
			__( 'We kept your previous Azure settings because validation failed.', 'flavor-agent' )
		);
		self::$azure_validation_error_reported = true;
	}

	private static function report_openai_native_validation_error( \WP_Error $error ): void {
		if ( self::$native_openai_validation_error_reported ) {
			return;
		}

		Feedback::report_validation_feedback(
			Config::GROUP_CHAT,
			'flavor_agent_openai_native_validation',
			$error,
			__( 'We kept your previous OpenAI Native settings because validation failed.', 'flavor-agent' )
		);
		self::$native_openai_validation_error_reported = true;
	}

	private static function report_qdrant_validation_error( \WP_Error $error ): void {
		if ( self::$qdrant_validation_error_reported ) {
			return;
		}

		Feedback::report_validation_feedback(
			Config::GROUP_PATTERNS,
			'flavor_agent_qdrant_validation',
			$error,
			__( 'We kept your previous Qdrant settings because validation failed.', 'flavor-agent' )
		);
		self::$qdrant_validation_error_reported = true;
	}

	private static function report_cloudflare_validation_error( \WP_Error $error ): void {
		if ( self::$cloudflare_validation_error_reported ) {
			return;
		}

		Feedback::report_validation_feedback(
			Config::GROUP_DOCS,
			'flavor_agent_cloudflare_ai_search_validation',
			$error,
			__( 'We kept your previous docs grounding settings because validation failed.', 'flavor-agent' )
		);
		self::$cloudflare_validation_error_reported = true;
	}

	private function __construct() {
	}
}
