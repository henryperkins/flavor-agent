<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Embeddings\EmbeddingClient;
use FlavorAgent\Embeddings\QdrantClient;
use FlavorAgent\Cloudflare\PatternSearchClient;
use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;
use FlavorAgent\Patterns\PatternIndex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Validation {


	private const SECRET_OPTION_NAMES = [
		'flavor_agent_cloudflare_workers_ai_api_token',
		'flavor_agent_qdrant_key',
	];

	private const SECRET_OPTION_COMPANIONS = [
		'flavor_agent_cloudflare_workers_ai_api_token' => [
			'flavor_agent_cloudflare_workers_ai_account_id',
			'flavor_agent_cloudflare_workers_ai_embedding_model',
		],
		'flavor_agent_qdrant_key'                      => [
			'flavor_agent_qdrant_url',
		],
	];

	private const UNPOSTED_PROVIDER_OPTION_DEFAULTS = [
		'flavor_agent_cloudflare_workers_ai_account_id' => '',
		'flavor_agent_cloudflare_workers_ai_api_token'  => '',
		'flavor_agent_cloudflare_workers_ai_embedding_model' => WorkersAIEmbeddingConfiguration::DEFAULT_MODEL,
	];

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $workers_ai_validation_state = null;

	private static bool $workers_ai_validation_error_reported = false;

	private static bool $workers_ai_dimension_warning_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $qdrant_validation_state = null;

	private static bool $qdrant_validation_error_reported = false;

	/**
	 * @var array{fingerprint: string, values: array<string, string>, error: \WP_Error|null}|null
	 */
	private static ?array $pattern_ai_search_validation_state = null;

	private static bool $pattern_ai_search_validation_error_reported = false;

	/**
	 * @var array<string, array{request_fingerprint: string, values: array<string, string>}>
	 */
	private static array $submission_value_cache = [];

	/**
	 * @var array{fingerprint: string}|null
	 */
	private static ?array $submission_request_fingerprint = null;

	public static function reset(): void {
		self::$workers_ai_validation_state                 = null;
		self::$workers_ai_validation_error_reported        = false;
		self::$workers_ai_dimension_warning_reported       = false;
		self::$qdrant_validation_state                     = null;
		self::$qdrant_validation_error_reported            = false;
		self::$pattern_ai_search_validation_state          = null;
		self::$pattern_ai_search_validation_error_reported = false;
		self::$submission_value_cache                      = [];
		self::$submission_request_fingerprint              = null;
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

	public static function sanitize_pattern_recommendation_threshold_cloudflare_ai_search( mixed $value ): float {
		$threshold = (float) $value;

		$threshold = max( 0.0, min( 1.0, round( $threshold, 2 ) ) );
		Feedback::mark_section_changed_by_option(
			Config::OPTION_PATTERN_RECOMMENDATION_THRESHOLD_CLOUDFLARE_AI_SEARCH,
			$threshold
		);

		return $threshold;
	}

	public static function sanitize_pattern_retrieval_backend( mixed $value ): string {
		$backend = sanitize_key( (string) $value );

		if ( ! in_array( $backend, Config::PATTERN_BACKENDS, true ) ) {
			$backend = Config::PATTERN_BACKEND_QDRANT;
		}

		Feedback::mark_section_changed_by_option( Config::OPTION_PATTERN_RETRIEVAL_BACKEND, $backend );

		return $backend;
	}

	public static function sanitize_pattern_max_recommendations( mixed $value ): int {
		$count = max( 1, min( Config::PATTERN_MAX_RECOMMENDATIONS_LIMIT, (int) $value ) );
		Feedback::mark_section_changed_by_option( 'flavor_agent_pattern_max_recommendations', $count );

		return $count;
	}

	public static function sanitize_block_structural_actions_enabled( mixed $value ): bool {
		$enabled = self::parse_boolean_flag( $value );
		Feedback::mark_section_changed_by_option( Config::OPTION_BLOCK_STRUCTURAL_ACTIONS, $enabled );

		return $enabled;
	}

	public static function sanitize_reasoning_effort( mixed $value ): string {
		$preserved_value = self::preserve_unposted_reasoning_effort_value();
		if ( null !== $preserved_value ) {
			return $preserved_value;
		}

		$effort = self::sanitize_reasoning_effort_value( $value ) ?? 'medium';
		Feedback::mark_section_changed_by_option( Config::OPTION_REASONING_EFFORT, $effort );

		return $effort;
	}

	public static function sanitize_openai_provider( mixed $value ): string {
		unset( $value );

		$provider = \FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration::PROVIDER;
		Feedback::mark_section_changed_by_option( Provider::OPTION_NAME, $provider );

		return $provider;
	}

	public static function sanitize_cloudflare_workers_ai_account_id( mixed $value ): string {
		return self::sanitize_workers_ai_text_option(
			$value,
			'flavor_agent_cloudflare_workers_ai_account_id'
		);
	}

	public static function sanitize_cloudflare_workers_ai_api_token( mixed $value ): string {
		return self::sanitize_workers_ai_text_option(
			$value,
			'flavor_agent_cloudflare_workers_ai_api_token'
		);
	}

	public static function sanitize_cloudflare_workers_ai_embedding_model( mixed $value ): string {
		return self::sanitize_workers_ai_text_option(
			$value,
			'flavor_agent_cloudflare_workers_ai_embedding_model'
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
		$sanitized_value = self::sanitize_text_option_value( $value, 'flavor_agent_qdrant_key' );
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

	public static function sanitize_cloudflare_pattern_ai_search_account_id( mixed $value ): string {
		return self::sanitize_pattern_ai_search_text_option(
			$value,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_ACCOUNT_ID
		);
	}

	public static function sanitize_cloudflare_pattern_ai_search_namespace( mixed $value ): string {
		return self::sanitize_pattern_ai_search_text_option(
			$value,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE
		);
	}

	public static function sanitize_cloudflare_pattern_ai_search_instance_id( mixed $value ): string {
		return self::sanitize_pattern_ai_search_text_option(
			$value,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID
		);
	}

	public static function sanitize_cloudflare_pattern_ai_search_api_token( mixed $value ): string {
		return self::sanitize_pattern_ai_search_text_option(
			$value,
			Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_API_TOKEN
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
	public static function resolve_workers_ai_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_workers_ai_values();
		$values         = self::get_cached_submission_values(
			'cloudflare_workers_ai',
			static function () use ( $current_values ): array {
				return [
					'flavor_agent_cloudflare_workers_ai_account_id' => self::read_posted_text_value(
						'flavor_agent_cloudflare_workers_ai_account_id',
						$current_values['flavor_agent_cloudflare_workers_ai_account_id']
					),
					'flavor_agent_cloudflare_workers_ai_api_token' => self::read_posted_text_value(
						'flavor_agent_cloudflare_workers_ai_api_token',
						$current_values['flavor_agent_cloudflare_workers_ai_api_token']
					),
					'flavor_agent_cloudflare_workers_ai_embedding_model' => self::read_posted_text_value(
						'flavor_agent_cloudflare_workers_ai_embedding_model',
						$current_values['flavor_agent_cloudflare_workers_ai_embedding_model']
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
			'' === $values['flavor_agent_cloudflare_workers_ai_account_id'] ||
			'' === $values['flavor_agent_cloudflare_workers_ai_api_token']
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$workers_ai_validation_state ) &&
			( self::$workers_ai_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$workers_ai_validation_state['error'] instanceof \WP_Error
				? self::$workers_ai_validation_state['error']
				: self::$workers_ai_validation_state['values'];
		}

		$validation = EmbeddingClient::validate_configuration(
			$values['flavor_agent_cloudflare_workers_ai_account_id'],
			$values['flavor_agent_cloudflare_workers_ai_api_token'],
			$values['flavor_agent_cloudflare_workers_ai_embedding_model']
		);

		self::$workers_ai_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		self::maybe_report_workers_ai_dimension_warning( $validation );

		return $values;
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
	public static function resolve_pattern_ai_search_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_pattern_ai_search_values();
		$values         = self::get_cached_submission_values(
			'cloudflare_pattern_ai_search',
			static function () use ( $current_values ): array {
				$workers_ai_values = self::resolve_workers_ai_submission_values();

				if ( is_wp_error( $workers_ai_values ) ) {
					$workers_ai_values = self::get_current_workers_ai_values();
				}

				return [
					'flavor_agent_cloudflare_workers_ai_account_id' => $workers_ai_values['flavor_agent_cloudflare_workers_ai_account_id'] ?? '',
					'flavor_agent_cloudflare_workers_ai_api_token' => $workers_ai_values['flavor_agent_cloudflare_workers_ai_api_token'] ?? '',
					Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE => Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE,
					Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => self::read_posted_text_value(
						Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID,
						$current_values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ]
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
			'' === $values['flavor_agent_cloudflare_workers_ai_account_id'] ||
			'' === $values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE ] ||
			'' === $values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ] ||
			'' === $values['flavor_agent_cloudflare_workers_ai_api_token']
		) {
			return $values;
		}

		if ( ! self::values_require_validation( $values, $current_values ) ) {
			return $values;
		}

		$fingerprint = self::build_validation_fingerprint( $values );

		if (
			is_array( self::$pattern_ai_search_validation_state ) &&
			( self::$pattern_ai_search_validation_state['fingerprint'] ?? '' ) === $fingerprint
		) {
			return self::$pattern_ai_search_validation_state['error'] instanceof \WP_Error
				? self::$pattern_ai_search_validation_state['error']
				: self::$pattern_ai_search_validation_state['values'];
		}

		$validation = PatternSearchClient::validate_configuration(
			$values['flavor_agent_cloudflare_workers_ai_account_id'],
			$values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE ],
			$values[ Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID ],
			$values['flavor_agent_cloudflare_workers_ai_api_token']
		);

		self::$pattern_ai_search_validation_state = [
			'fingerprint' => $fingerprint,
			'values'      => $values,
			'error'       => is_wp_error( $validation ) ? $validation : null,
		];

		return is_wp_error( $validation ) ? $validation : $values;
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
		if (
			defined( 'FLAVOR_AGENT_TESTS_RUNNING' ) &&
			function_exists( 'wp_get_environment_type' ) &&
			'tests' === wp_get_environment_type()
		) {
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

	private static function sanitize_workers_ai_text_option( mixed $value, string $option_name ): string {
		$preserved_value = self::preserve_unposted_provider_option_value( $option_name );
		if ( null !== $preserved_value ) {
			return $preserved_value;
		}

		$sanitized_value = self::sanitize_text_option_value( $value, $option_name );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_workers_ai_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_workers_ai_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_pattern_ai_search_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = self::sanitize_text_option_value( $value, $option_name );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );
		$resolved_values = self::resolve_pattern_ai_search_submission_values(
			[
				$option_name => $sanitized_value,
			]
		);

		if ( is_wp_error( $resolved_values ) ) {
			self::report_pattern_ai_search_validation_error( $resolved_values );

			return (string) get_option( $option_name, '' );
		}

		return $resolved_values[ $option_name ] ?? $sanitized_value;
	}

	private static function sanitize_guideline_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = Guidelines::sanitize_guideline_text( $value );
		Feedback::mark_section_changed_by_option( $option_name, $sanitized_value );

		return $sanitized_value;
	}

	private static function sanitize_text_option_value( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );

		if ( '' !== $sanitized_value || ! self::should_preserve_blank_secret( $option_name ) ) {
			return $sanitized_value;
		}

		return sanitize_text_field( (string) get_option( $option_name, '' ) );
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_workers_ai_values(): array {
		return self::get_current_option_values(
			[
				'flavor_agent_cloudflare_workers_ai_account_id' => 'sanitize_text_field',
				'flavor_agent_cloudflare_workers_ai_api_token' => 'sanitize_text_field',
				'flavor_agent_cloudflare_workers_ai_embedding_model' => 'sanitize_text_field',
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
	private static function get_current_pattern_ai_search_values(): array {
		return self::get_current_option_values(
			[
				'flavor_agent_cloudflare_workers_ai_account_id' => 'sanitize_text_field',
				'flavor_agent_cloudflare_workers_ai_api_token' => 'sanitize_text_field',
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE => static fn( string $value ): string => Config::DEFAULT_CLOUDFLARE_PATTERN_AI_SEARCH_NAMESPACE,
				Config::OPTION_CLOUDFLARE_PATTERN_AI_SEARCH_INSTANCE_ID => 'sanitize_text_field',
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

	private static function has_posted_option( string $option_name ): bool {
		if ( ! self::has_valid_submission_nonce() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is validated above; this checks presence only before sanitized reads happen elsewhere.
		return array_key_exists( $option_name, $_POST );
	}

	private static function preserve_unposted_provider_option_value( string $option_name ): ?string {
		if ( ! self::should_validate_submission() ) {
			return null;
		}

		if ( self::has_posted_option( $option_name ) ) {
			return null;
		}

		if ( ! array_key_exists( $option_name, self::UNPOSTED_PROVIDER_OPTION_DEFAULTS ) ) {
			return null;
		}

		return sanitize_text_field(
			(string) get_option(
				$option_name,
				self::UNPOSTED_PROVIDER_OPTION_DEFAULTS[ $option_name ]
			)
		);
	}

	private static function preserve_unposted_reasoning_effort_value(): ?string {
		if ( ! self::should_validate_submission() ) {
			return null;
		}

		if ( self::has_posted_option( Config::OPTION_REASONING_EFFORT ) ) {
			return null;
		}

		return self::get_saved_reasoning_effort_value();
	}

	private static function get_saved_reasoning_effort_value(): string {
		$saved = self::sanitize_reasoning_effort_value(
			get_option( Config::OPTION_REASONING_EFFORT, '' )
		);

		if ( null !== $saved ) {
			return $saved;
		}

		$legacy_saved = self::sanitize_reasoning_effort_value(
			get_option( Config::OPTION_LEGACY_AZURE_REASONING_EFFORT, '' )
		);

		return $legacy_saved ?? 'medium';
	}

	private static function sanitize_reasoning_effort_value( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$effort = sanitize_key( (string) $value );

		return in_array( $effort, [ 'low', 'medium', 'high', 'xhigh' ], true )
			? $effort
			: null;
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

		$sanitized_value = sanitize_text_field( $value );

		if ( '' !== $sanitized_value || ! self::should_preserve_blank_secret( $option_name ) ) {
			return $sanitized_value;
		}

		return sanitize_text_field( $fallback );
	}

	private static function should_preserve_blank_secret( string $option_name ): bool {
		if ( ! in_array( $option_name, self::SECRET_OPTION_NAMES, true ) ) {
			return false;
		}

		if ( '' === (string) get_option( $option_name, '' ) ) {
			return false;
		}

		$companion_options = self::SECRET_OPTION_COMPANIONS[ $option_name ] ?? [];

		foreach ( $companion_options as $companion_option ) {
			if ( ! self::has_posted_option( $companion_option ) ) {
				return true;
			}

			$companion_value = self::get_raw_posted_string( $companion_option );

			if ( null === $companion_value ) {
				return true;
			}

			$sanitized_companion = str_ends_with( $companion_option, '_url' ) || str_ends_with( $companion_option, '_endpoint' )
				? Utils::sanitize_url_value( $companion_value )
				: sanitize_text_field( $companion_value );

			if ( '' !== $sanitized_companion ) {
				return true;
			}
		}

		return [] === $companion_options;
	}

	private static function get_raw_posted_string( string $option_name ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers validate the nonce first and sanitize the returned value before use.
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = wp_unslash( $value );

		return is_string( $value ) ? $value : null;
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
		$post_data     = wp_unslash( $raw_post_data );
		$payload       = wp_json_encode( is_array( $post_data ) ? $post_data : [] );

		if ( ! is_string( $payload ) || '' === $payload ) {
			$payload = '';
		}

		$fingerprint = md5( $payload );

		self::$submission_request_fingerprint = [
			'fingerprint' => $fingerprint,
		];

		return $fingerprint;
	}

	private static function report_workers_ai_validation_error( \WP_Error $error ): void {
		if ( self::$workers_ai_validation_error_reported ) {
			return;
		}

		Feedback::report_validation_feedback(
			Config::GROUP_EMBEDDINGS,
			'flavor_agent_cloudflare_workers_ai_validation',
			$error,
			__( 'We kept your previous Cloudflare Workers AI settings because validation failed.', 'flavor-agent' )
		);
		self::$workers_ai_validation_error_reported = true;
	}

	/**
	 * @param array{dimension?: int} $validation
	 */
	private static function maybe_report_workers_ai_dimension_warning( array $validation ): void {
		if ( self::$workers_ai_dimension_warning_reported ) {
			return;
		}

		$validated_dimension = max( 0, (int) ( $validation['dimension'] ?? 0 ) );
		if ( 0 === $validated_dimension ) {
			return;
		}

		$selected_backend = sanitize_key(
			(string) get_option( Config::OPTION_PATTERN_RETRIEVAL_BACKEND, Config::PATTERN_BACKEND_QDRANT )
		);
		if ( Config::PATTERN_BACKEND_QDRANT !== $selected_backend ) {
			return;
		}

		$state            = PatternIndex::get_state();
		$stored_dimension = max( 0, (int) ( $state['embedding_dimension'] ?? 0 ) );
		if (
			0 === $stored_dimension
			|| $stored_dimension === $validated_dimension
			|| empty( $state['last_synced_at'] )
		) {
			return;
		}

		$message = sprintf(
			/* translators: 1: previous embedding dimension, 2: validated embedding dimension */
			__( 'The saved Qdrant pattern index uses %1$d embedding dimensions, but the validated Cloudflare Workers AI model returns %2$d. Re-sync patterns before relying on Qdrant pattern recommendations.', 'flavor-agent' ),
			$stored_dimension,
			$validated_dimension
		);

		if ( '' !== Feedback::get_request_key_from_post() ) {
			Feedback::record_section_feedback_messages(
				Config::GROUP_EMBEDDINGS,
				[
					[
						'tone'    => 'warning',
						'message' => $message,
					],
				],
				true
			);
		} else {
			add_settings_error(
				Config::OPTION_GROUP,
				'flavor_agent_workers_ai_dimension_changed',
				$message,
				'warning'
			);
		}

		self::$workers_ai_dimension_warning_reported = true;
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

	private static function report_pattern_ai_search_validation_error( \WP_Error $error ): void {
		if ( self::$pattern_ai_search_validation_error_reported ) {
			return;
		}

		Feedback::report_validation_feedback(
			Config::GROUP_PATTERNS,
			'flavor_agent_cloudflare_pattern_ai_search_validation',
			$error,
			__( 'We kept your previous private Cloudflare AI Search pattern settings because validation failed.', 'flavor-agent' )
		);
		self::$pattern_ai_search_validation_error_reported = true;
	}

	private static function parse_boolean_flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_float( $value ) ) {
			return 1.0 === $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
		}

		return false;
	}

	private function __construct() {}
}
