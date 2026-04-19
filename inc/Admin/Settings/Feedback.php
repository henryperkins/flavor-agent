<?php

declare(strict_types=1);

namespace FlavorAgent\Admin\Settings;

use FlavorAgent\Guidelines;
use FlavorAgent\OpenAI\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Feedback {

	/**
	 * @return array<string, mixed>
	 */
	public static function get_default_feedback(): array {
		return [
			'changed_sections' => [],
			'messages'         => [],
			'focus_section'    => '',
		];
	}

	public static function generate_request_key(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $exception ) {
			return substr( hash( 'sha256', uniqid( '', true ) ), 0, 16 );
		}
	}

	public static function render_feedback_request_fields( string $feedback_request_key ): void {
		printf(
			'<input type="hidden" name="%1$s" value="%2$s" /><input type="hidden" name="_wp_http_referer" value="%3$s" />',
			esc_attr( Config::PAGE_FEEDBACK_FIELD_NAME ),
			esc_attr( $feedback_request_key ),
			esc_attr( self::get_form_referer( $feedback_request_key ) )
		);
	}

	public static function get_form_referer( string $feedback_request_key ): string {
		return Utils::sanitize_url_value(
			admin_url(
				sprintf(
					'options-general.php?page=%1$s&%2$s=%3$s',
					Config::PAGE_SLUG,
					Config::PAGE_FEEDBACK_QUERY_KEY,
					rawurlencode( $feedback_request_key )
				)
			)
		);
	}

	public static function get_request_key_from_post(): string {
		if ( ! Validation::has_valid_submission_nonce() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated above; the value is unslashed and sanitized immediately below.
		$feedback_key = wp_unslash( $_POST[ Config::PAGE_FEEDBACK_FIELD_NAME ] ?? '' );

		return self::sanitize_request_key( $feedback_key );
	}

	public static function get_request_key_from_query(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only query param used to match request-scoped feedback after the save redirect.
		$feedback_key = wp_unslash( $_GET[ Config::PAGE_FEEDBACK_QUERY_KEY ] ?? '' );

		return self::sanitize_request_key( $feedback_key );
	}

	public static function sanitize_request_key( mixed $feedback_key ): string {
		if ( ! is_string( $feedback_key ) ) {
			return '';
		}

		return substr( sanitize_key( $feedback_key ), 0, 32 );
	}

	public static function get_storage_key( string $feedback_request_key ): string {
		if ( '' === $feedback_request_key ) {
			return '';
		}

		return sprintf(
			'%s%d_%s',
			Config::PAGE_FEEDBACK_TRANSIENT_PREFIX,
			max( 0, get_current_user_id() ),
			$feedback_request_key
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_settings_page_feedback(): array {
		return self::read_settings_page_feedback(
			self::get_request_key_from_post(),
			false
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function consume_settings_page_feedback(): array {
		return self::read_settings_page_feedback(
			self::get_request_key_from_query(),
			true
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function read_settings_page_feedback( string $feedback_request_key, bool $consume ): array {
		$storage_key = self::get_storage_key( $feedback_request_key );

		if ( '' === $storage_key ) {
			return self::get_default_feedback();
		}

		$feedback = get_transient( $storage_key );

		if ( is_array( $feedback ) ) {
			if ( $consume ) {
				delete_transient( $storage_key );
			}

			return array_merge(
				self::get_default_feedback(),
				$feedback
			);
		}

		return self::get_default_feedback();
	}

	/**
	 * @param array<string, mixed> $feedback
	 * @return array<int, array{tone: string, message: string}>
	 */
	public static function get_feedback_message_entries( array $feedback, string $section ): array {
		$messages = is_array( $feedback['messages'] ?? null ) ? $feedback['messages'] : [];
		$raw      = $messages[ $section ] ?? null;

		if ( ! is_array( $raw ) ) {
			return [];
		}

		if ( isset( $raw['tone'], $raw['message'] ) ) {
			$raw = [ $raw ];
		}

		$entries = [];

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$tone    = (string) ( $entry['tone'] ?? '' );
			$message = (string) ( $entry['message'] ?? '' );

			if ( '' === $tone || '' === $message ) {
				continue;
			}

			$entries[] = [
				'tone'    => $tone,
				'message' => $message,
			];
		}

		return $entries;
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	public static function feedback_group_has_tone( array $feedback, string $section, string $tone ): bool {
		foreach ( self::get_feedback_message_entries( $feedback, $section ) as $entry ) {
			if ( $tone === $entry['tone'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $feedback
	 */
	public static function persist_settings_page_feedback( array $feedback ): void {
		$storage_key = self::get_storage_key(
			self::get_request_key_from_post()
		);

		if ( '' === $storage_key ) {
			return;
		}

		set_transient( $storage_key, $feedback, Config::PAGE_FEEDBACK_TTL );
	}

	public static function mark_section_changed_by_option( string $option_name, mixed $next_value ): void {
		if ( ! Validation::should_validate_submission() ) {
			return;
		}

		$current_value = get_option( $option_name, '' );

		if (
			self::normalize_option_value_for_feedback( $current_value )
			=== self::normalize_option_value_for_feedback( $next_value )
		) {
			return;
		}

		$feedback = self::get_settings_page_feedback();

		foreach ( self::get_feedback_groups_for_option( $option_name ) as $group ) {
			$feedback['changed_sections'][ $group ] = true;
		}

		self::persist_settings_page_feedback( $feedback );
	}

	public static function record_section_feedback_message(
		string $section,
		string $tone,
		string $message,
		bool $focus = false
	): void {
		self::record_section_feedback_messages(
			$section,
			[
				[
					'tone'    => $tone,
					'message' => $message,
				],
			],
			$focus
		);
	}

	/**
	 * @param array<int, array{tone: string, message: string}> $entries
	 */
	public static function record_section_feedback_messages(
		string $section,
		array $entries,
		bool $focus = false
	): void {
		$feedback    = self::get_settings_page_feedback();
		$messages    = self::get_feedback_message_entries( $feedback, $section );
		$new_entries = [];

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$tone    = (string) ( $entry['tone'] ?? '' );
			$message = (string) ( $entry['message'] ?? '' );

			if ( '' === $tone || '' === $message ) {
				continue;
			}

			$new_entries[] = [
				'tone'    => $tone,
				'message' => $message,
			];
		}

		if ( [] === $new_entries ) {
			return;
		}

		$messages                         = array_merge( $messages, $new_entries );
		$feedback['messages'][ $section ] = $messages;

		if ( $focus && '' === (string) ( $feedback['focus_section'] ?? '' ) ) {
			$feedback['focus_section'] = $section;
		}

		self::persist_settings_page_feedback( $feedback );
	}

	public static function report_validation_feedback(
		string $section,
		string $settings_error_code,
		\WP_Error $error,
		string $preserved_message
	): void {
		if ( '' !== self::get_request_key_from_post() ) {
			self::record_section_feedback_messages(
				$section,
				[
					[
						'tone'    => 'error',
						'message' => $error->get_error_message(),
					],
					[
						'tone'    => 'warning',
						'message' => $preserved_message,
					],
				],
				true
			);
			return;
		}

		add_settings_error(
			Config::OPTION_GROUP,
			$settings_error_code,
			$error->get_error_message(),
			'error'
		);
		add_settings_error(
			Config::OPTION_GROUP,
			$settings_error_code . '_preserved',
			$preserved_message,
			'warning'
		);
	}

	public static function has_settings_updated_query_flag(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only query arg used only to render post-save notices.
		$settings_updated = wp_unslash( $_GET['settings-updated'] ?? '' );

		if ( ! is_string( $settings_updated ) ) {
			return false;
		}

		return 'true' === sanitize_text_field( $settings_updated );
	}

	/**
	 * @return array<int, string>
	 */
	private static function get_feedback_groups_for_option( string $option_name ): array {
		return match ( $option_name ) {
			Provider::OPTION_NAME,
			'flavor_agent_azure_chat_deployment',
			'flavor_agent_azure_reasoning_effort',
			'flavor_agent_openai_native_chat_model' => [ Config::GROUP_CHAT ],
			'flavor_agent_pattern_recommendation_threshold',
			'flavor_agent_pattern_max_recommendations',
			'flavor_agent_qdrant_url',
			'flavor_agent_qdrant_key',
			'flavor_agent_azure_embedding_deployment',
			'flavor_agent_openai_native_embedding_model' => [ Config::GROUP_PATTERNS ],
			'flavor_agent_cloudflare_ai_search_account_id',
			'flavor_agent_cloudflare_ai_search_instance_id',
			'flavor_agent_cloudflare_ai_search_api_token',
			'flavor_agent_cloudflare_ai_search_max_results' => [ Config::GROUP_DOCS ],
			Guidelines::OPTION_SITE,
			Guidelines::OPTION_COPY,
			Guidelines::OPTION_IMAGES,
			Guidelines::OPTION_ADDITIONAL,
			Guidelines::OPTION_BLOCKS => [ Config::GROUP_GUIDELINES ],
			'flavor_agent_azure_openai_endpoint',
			'flavor_agent_azure_openai_key',
			'flavor_agent_openai_native_api_key' => [ Config::GROUP_CHAT, Config::GROUP_PATTERNS ],
			default => [],
		};
	}

	private static function normalize_option_value_for_feedback( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? $encoded : '';
	}

	private function __construct() {
	}
}
