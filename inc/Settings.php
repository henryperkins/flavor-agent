<?php

declare(strict_types=1);

namespace FlavorAgent;

use FlavorAgent\AzureOpenAI\EmbeddingClient;
use FlavorAgent\AzureOpenAI\QdrantClient;
use FlavorAgent\AzureOpenAI\ResponsesClient;
use FlavorAgent\Cloudflare\AISearchClient;
use FlavorAgent\Patterns\PatternIndex;

final class Settings {

	private const OPTION_GROUP = 'flavor_agent_settings';
	private const PAGE_SLUG    = 'flavor-agent';

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
	private static ?array $qdrant_validation_state = null;

	private static bool $qdrant_validation_error_reported = false;

	public static function add_menu(): void {
		$hook = add_options_page(
			'Flavor Agent',
			'Flavor Agent',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		if ( $hook ) {
			add_action(
				'admin_enqueue_scripts',
				function ( string $page_hook ) use ( $hook ) {
					if ( $page_hook !== $hook ) {
						return;
					}
					self::enqueue_admin_assets();
				}
			);
		}
	}

	public static function register_settings(): void {
		// Azure OpenAI.
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_openai_endpoint',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_openai_endpoint' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_openai_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_openai_key' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_embedding_deployment',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_embedding_deployment' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_azure_chat_deployment',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_azure_chat_deployment' ],
				'default'           => '',
			]
		);

		// Qdrant.
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_qdrant_url',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_qdrant_url' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_qdrant_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_qdrant_key' ],
				'default'           => '',
			]
		);

		// Cloudflare AI Search.
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_account_id',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_cloudflare_account_id' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_instance_id',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_cloudflare_instance_id' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_api_token',
			[
				'type'              => 'string',
				'sanitize_callback' => [ __CLASS__, 'sanitize_cloudflare_api_token' ],
				'default'           => '',
			]
		);
		register_setting(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_max_results',
			[
				'type'              => 'integer',
				'sanitize_callback' => [ __CLASS__, 'sanitize_grounding_result_count' ],
				'default'           => 4,
			]
		);

		// --- Sections ---

		add_settings_section(
			'flavor_agent_azure',
			'Azure OpenAI (Pattern Recommendations)',
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_section(
			'flavor_agent_qdrant',
			'Qdrant Cloud (Vector Store)',
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_section(
			'flavor_agent_cloudflare',
			'Cloudflare AI Search (Official WordPress Docs Grounding)',
			[ __CLASS__, 'render_cloudflare_section' ],
			self::PAGE_SLUG
		);

		// --- Azure OpenAI fields ---

		add_settings_field(
			'flavor_agent_azure_openai_endpoint',
			'Endpoint',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_openai_endpoint',
				'type'        => 'url',
				'placeholder' => 'https://....openai.azure.com/',
			]
		);
		add_settings_field(
			'flavor_agent_azure_openai_key',
			'API Key',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option' => 'flavor_agent_azure_openai_key',
				'type'   => 'password',
			]
		);
		add_settings_field(
			'flavor_agent_azure_embedding_deployment',
			'Embedding Deployment',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_embedding_deployment',
				'placeholder' => 'text-embedding-3-large',
			]
		);
		add_settings_field(
			'flavor_agent_azure_chat_deployment',
			'Chat Deployment',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_azure',
			[
				'option'      => 'flavor_agent_azure_chat_deployment',
				'placeholder' => 'gpt-5.4',
			]
		);

		// --- Qdrant fields ---

		add_settings_field(
			'flavor_agent_qdrant_url',
			'Qdrant URL',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_qdrant',
			[
				'option'      => 'flavor_agent_qdrant_url',
				'type'        => 'url',
				'placeholder' => 'https://....cloud.qdrant.io:6333',
			]
		);
		add_settings_field(
			'flavor_agent_qdrant_key',
			'API Key',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_qdrant',
			[
				'option' => 'flavor_agent_qdrant_key',
				'type'   => 'password',
			]
		);

		// --- Cloudflare AI Search fields ---

		add_settings_field(
			'flavor_agent_cloudflare_ai_search_account_id',
			'Account ID',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_account_id',
				'placeholder' => 'Cloudflare account ID',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_instance_id',
			'WordPress Docs AI Search ID',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_instance_id',
				'placeholder' => 'wordpress-developer-docs',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_api_token',
			'API Token',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option' => 'flavor_agent_cloudflare_ai_search_api_token',
				'type'   => 'password',
			]
		);
		add_settings_field(
			'flavor_agent_cloudflare_ai_search_max_results',
			'Grounding Results',
			[ __CLASS__, 'render_text_field' ],
			self::PAGE_SLUG,
			'flavor_agent_cloudflare',
			[
				'option'      => 'flavor_agent_cloudflare_ai_search_max_results',
				'type'        => 'number',
				'placeholder' => '4',
			]
		);
	}

	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1>Flavor Agent Settings</h1>
			<?php self::render_settings_notices(); ?>
			<p class="description">
				<?php
				echo esc_html__(
					'Block recommendations use the core Settings > Connectors screen and the WordPress AI Client. This page only manages Azure, Qdrant, Cloudflare, and pattern sync settings.',
					'flavor-agent'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />
			<h2>Sync Pattern Catalog</h2>
			<?php self::render_sync_panel(); ?>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Field renderers
	// ------------------------------------------------------------------

	private static function render_settings_notices(): void {
		// Render all Settings API notices so core success messages and plugin-specific
		// validation errors both survive the post-save redirect.
		settings_errors();
	}

	public static function render_cloudflare_section(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Flavor Agent only keeps Cloudflare grounding chunks whose source resolves to developer.wordpress.org. Mixed or private content in the configured index is ignored, and the configured account, instance, and token are validated only when those credentials change. The target instance must be enabled, not paused, and must return trusted developer.wordpress.org guidance before new credentials are saved.',
				'flavor-agent'
			)
		);

		self::render_prewarm_diagnostics();
	}

	/**
	 * Generic text/password/url field renderer driven by $args.
	 */
	public static function render_text_field( array $args ): void {
		$option      = $args['option'] ?? '';
		$type        = $args['type'] ?? 'text';
		$placeholder = $args['placeholder'] ?? '';
		$value       = get_option( $option, '' );

		printf(
			'<input type="%s" name="%s" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
			esc_attr( $type ),
			esc_attr( $option ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	public static function sanitize_grounding_result_count( mixed $value ): int {
		return max( 1, min( 8, (int) $value ) );
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

	public static function sanitize_qdrant_url( mixed $value ): string {
		$sanitized_value = self::sanitize_url_value( $value );
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

	private static function sanitize_azure_url_option( mixed $value, string $option_name ): string {
		$sanitized_value = self::sanitize_url_value( $value );
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

	private static function sanitize_cloudflare_text_option( mixed $value, string $option_name ): string {
		$sanitized_value = sanitize_text_field( $value );
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

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>|\WP_Error
	 */
	private static function resolve_azure_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_azure_values();
		$values         = [
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

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = 'flavor_agent_azure_openai_endpoint' === $option_name
				? self::sanitize_url_value( $override_value )
				: sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_provider_submission() ) {
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
			$values['flavor_agent_azure_embedding_deployment']
		);

		if ( ! is_wp_error( $validation ) ) {
			$validation = ResponsesClient::validate_configuration(
				$values['flavor_agent_azure_openai_endpoint'],
				$values['flavor_agent_azure_openai_key'],
				$values['flavor_agent_azure_chat_deployment']
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
	private static function resolve_qdrant_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_qdrant_values();
		$values         = [
			'flavor_agent_qdrant_url' => self::read_posted_url_value(
				'flavor_agent_qdrant_url',
				$current_values['flavor_agent_qdrant_url']
			),
			'flavor_agent_qdrant_key' => self::read_posted_text_value(
				'flavor_agent_qdrant_key',
				$current_values['flavor_agent_qdrant_key']
			),
		];

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = 'flavor_agent_qdrant_url' === $option_name
				? self::sanitize_url_value( $override_value )
				: sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_provider_submission() ) {
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
	private static function resolve_cloudflare_submission_values( array $overrides = [] ): array|\WP_Error {
		$current_values = self::get_current_cloudflare_values();
		$values         = [
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

		foreach ( $overrides as $option_name => $override_value ) {
			$values[ $option_name ] = sanitize_text_field( $override_value );
		}

		if ( ! self::should_validate_provider_submission() ) {
			return $values;
		}

		if (
			$values['flavor_agent_cloudflare_ai_search_account_id'] === '' ||
			$values['flavor_agent_cloudflare_ai_search_instance_id'] === '' ||
			$values['flavor_agent_cloudflare_ai_search_api_token'] === ''
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

	/**
	 * @return array<string, string>
	 */
	private static function get_current_azure_values(): array {
		return [
			'flavor_agent_azure_openai_endpoint'      => self::sanitize_url_value(
				(string) get_option( 'flavor_agent_azure_openai_endpoint', '' )
			),
			'flavor_agent_azure_openai_key'           => sanitize_text_field(
				(string) get_option( 'flavor_agent_azure_openai_key', '' )
			),
			'flavor_agent_azure_embedding_deployment' => sanitize_text_field(
				(string) get_option( 'flavor_agent_azure_embedding_deployment', '' )
			),
			'flavor_agent_azure_chat_deployment'      => sanitize_text_field(
				(string) get_option( 'flavor_agent_azure_chat_deployment', '' )
			),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_qdrant_values(): array {
		return [
			'flavor_agent_qdrant_url' => self::sanitize_url_value(
				(string) get_option( 'flavor_agent_qdrant_url', '' )
			),
			'flavor_agent_qdrant_key' => sanitize_text_field(
				(string) get_option( 'flavor_agent_qdrant_key', '' )
			),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_current_cloudflare_values(): array {
		return [
			'flavor_agent_cloudflare_ai_search_account_id' => sanitize_text_field(
				(string) get_option( 'flavor_agent_cloudflare_ai_search_account_id', '' )
			),
			'flavor_agent_cloudflare_ai_search_instance_id' => sanitize_text_field(
				(string) get_option( 'flavor_agent_cloudflare_ai_search_instance_id', '' )
			),
			'flavor_agent_cloudflare_ai_search_api_token'  => sanitize_text_field(
				(string) get_option( 'flavor_agent_cloudflare_ai_search_api_token', '' )
			),
		];
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

	private static function should_validate_provider_submission(): bool {
		$option_page = $_POST['option_page'] ?? null;

		if ( ! is_string( $option_page ) ) {
			return false;
		}

		if ( function_exists( 'wp_unslash' ) ) {
			$option_page = wp_unslash( $option_page );
		}

		return sanitize_text_field( $option_page ) === self::OPTION_GROUP;
	}

	private static function read_posted_cloudflare_value( string $option_name, string $fallback ): string {
		return self::read_posted_text_value( $option_name, $fallback );
	}

	private static function read_posted_text_value( string $option_name, string $fallback ): string {
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return sanitize_text_field( $fallback );
		}

		if ( function_exists( 'wp_unslash' ) ) {
			$value = wp_unslash( $value );
		}

		return sanitize_text_field( $value );
	}

	private static function read_posted_url_value( string $option_name, string $fallback ): string {
		$value = $_POST[ $option_name ] ?? null;

		if ( ! is_string( $value ) ) {
			return self::sanitize_url_value( $fallback );
		}

		if ( function_exists( 'wp_unslash' ) ) {
			$value = wp_unslash( $value );
		}

		return self::sanitize_url_value( $value );
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

	private static function sanitize_url_value( mixed $value ): string {
		if ( function_exists( 'sanitize_url' ) ) {
			return (string) sanitize_url( (string) $value );
		}

		return sanitize_text_field( (string) $value );
	}

	private static function report_azure_validation_error( \WP_Error $error ): void {
		if ( self::$azure_validation_error_reported ) {
			return;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'flavor_agent_azure_validation',
			$error->get_error_message(),
			'error'
		);

		self::$azure_validation_error_reported = true;
	}

	private static function report_qdrant_validation_error( \WP_Error $error ): void {
		if ( self::$qdrant_validation_error_reported ) {
			return;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'flavor_agent_qdrant_validation',
			$error->get_error_message(),
			'error'
		);

		self::$qdrant_validation_error_reported = true;
	}

	private static function report_cloudflare_validation_error( \WP_Error $error ): void {
		if ( self::$cloudflare_validation_error_reported ) {
			return;
		}

		add_settings_error(
			self::OPTION_GROUP,
			'flavor_agent_cloudflare_ai_search_validation',
			$error->get_error_message(),
			'error'
		);

		self::$cloudflare_validation_error_reported = true;
	}

	// ------------------------------------------------------------------
	// Prewarm diagnostics panel
	// ------------------------------------------------------------------

	private static function render_prewarm_diagnostics(): void {
		if ( ! AISearchClient::is_configured() ) {
			return;
		}

		$state = AISearchClient::get_prewarm_state();

		$status_labels = [
			'never'     => 'Never run',
			'ok'        => 'OK',
			'partial'   => 'Partial (some entities failed)',
			'failed'    => 'Failed',
			'throttled' => 'Throttled (skipped, too recent)',
		];

		$label = $status_labels[ $state['status'] ] ?? $state['status'];
		?>
		<table class="form-table" role="presentation" style="margin-top:0">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Docs Prewarm', 'flavor-agent' ); ?></th>
				<td>
					<strong><?php echo esc_html( $label ); ?></strong>
					<?php if ( $state['timestamp'] !== '' ) : ?>
						&mdash; <?php echo esc_html( $state['timestamp'] ); ?> UTC
					<?php endif; ?>
					<?php if ( $state['warmed'] > 0 || $state['failed'] > 0 ) : ?>
						<br /><span class="description">
							<?php
							printf(
								/* translators: 1: warmed count, 2: failed count */
								esc_html__( '%1$d warmed, %2$d failed', 'flavor-agent' ),
								$state['warmed'],
								$state['failed']
							);
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	// ------------------------------------------------------------------
	// Sync status panel
	// ------------------------------------------------------------------

	private static function render_sync_panel(): void {
		$state = PatternIndex::get_runtime_state();

		$status_labels = [
			'uninitialized' => 'Not synced yet',
			'indexing'      => 'Syncing…',
			'ready'         => 'Ready',
			'stale'         => 'Stale (usable, refresh pending)',
			'error'         => 'Error',
		];

		$label = $status_labels[ $state['status'] ] ?? $state['status'];
		?>
		<table class="form-table">
			<tr>
				<th scope="row">Status</th>
				<td><strong><?php echo esc_html( $label ); ?></strong></td>
			</tr>
			<?php if ( $state['indexed_count'] > 0 ) : ?>
			<tr>
				<th scope="row">Indexed Patterns</th>
				<td><?php echo (int) $state['indexed_count']; ?></td>
			</tr>
			<?php endif; ?>
			<?php if ( $state['last_synced_at'] ) : ?>
			<tr>
				<th scope="row">Last Synced</th>
				<td><?php echo esc_html( $state['last_synced_at'] ); ?></td>
			</tr>
			<?php endif; ?>
			<?php if ( $state['last_error'] ) : ?>
			<tr>
				<th scope="row">Last Error</th>
				<td style="color:#d63638"><?php echo esc_html( $state['last_error'] ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row">Qdrant Collection</th>
				<td><code><?php echo esc_html( $state['qdrant_collection'] ? $state['qdrant_collection'] : QdrantClient::get_collection_name() ); ?></code></td>
			</tr>
		</table>

		<p>
			<button type="button" id="flavor-agent-sync-button" class="button button-secondary">
				Sync Pattern Catalog
			</button>
			<span id="flavor-agent-sync-spinner" class="spinner" aria-hidden="true"></span>
			<span id="flavor-agent-sync-status" style="margin-left:10px"></span>
		</p>
		<div id="flavor-agent-sync-notice" aria-live="polite"></div>
		<?php
	}

	// ------------------------------------------------------------------
	// Admin JS
	// ------------------------------------------------------------------

	private static function enqueue_admin_assets(): void {
		$asset_path = FLAVOR_AGENT_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset = include $asset_path;

		wp_enqueue_script(
			'flavor-agent-admin',
			FLAVOR_AGENT_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'flavor-agent-admin',
			'flavorAgentAdmin',
			[
				'restUrl' => rest_url(),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}
}
