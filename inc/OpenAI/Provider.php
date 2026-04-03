<?php

declare(strict_types=1);

namespace FlavorAgent\OpenAI;

use FlavorAgent\LLM\WordPressAIClient;

final class Provider {

	public const OPTION_NAME = 'flavor_agent_openai_provider';
	public const AZURE       = 'azure_openai';
	public const NATIVE      = 'openai_native';

	private const OPENAI_CONNECTOR_ID     = 'openai';
	private const CONNECTOR_OPENAI_OPTION = 'connectors_ai_openai_api_key';
	private const NATIVE_API_KEY_ENV_VAR  = 'OPENAI_API_KEY';
	private const NATIVE_BASE_URL         = 'https://api.openai.com';

	/**
	 * @return array<string, string>
	 */
	public static function direct_choices(): array {
		return [
			self::AZURE  => 'Azure OpenAI',
			self::NATIVE => 'OpenAI Native',
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function choices( ?string $selected_provider = null ): array {
		return self::direct_choices() + self::selectable_connector_choices( $selected_provider );
	}

	public static function get(): string {
		$provider = sanitize_key( (string) get_option( self::OPTION_NAME, self::AZURE ) );

		if ( isset( self::all_choices()[ $provider ] ) ) {
			return $provider;
		}

		return self::AZURE;
	}

	public static function label( ?string $provider = null ): string {
		$provider = sanitize_key( (string) ( $provider ?? self::get() ) );
		$choices  = self::all_choices();

		return $choices[ $provider ] ?? self::direct_choices()[ self::AZURE ];
	}

	public static function is_azure( ?string $provider = null ): bool {
		return ( $provider ?? self::get() ) === self::AZURE;
	}

	public static function is_native( ?string $provider = null ): bool {
		return ( $provider ?? self::get() ) === self::NATIVE;
	}

	public static function is_connector( ?string $provider = null ): bool {
		$provider = sanitize_key( (string) ( $provider ?? self::get() ) );

		return isset( self::registered_connector_choices()[ $provider ] );
	}

	public static function native_base_url(): string {
		return self::NATIVE_BASE_URL;
	}

	/**
	 * @return array{
	 *   registered: bool,
	 *   configured: bool,
	 *   label: string,
	 *   settingName: string,
	 *   keySource: 'env'|'constant'|'database'|'none',
	 *   credentialsUrl: ?string,
	 *   pluginSlug: ?string
	 * }
	 */
	public static function openai_connector_status(): array {
		$connector       = self::openai_connector();
		$authentication  = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : [];
		$plugin          = is_array( $connector['plugin'] ?? null ) ? $connector['plugin'] : [];
		$setting_name    = is_string( $authentication['setting_name'] ?? null ) && '' !== $authentication['setting_name']
			? $authentication['setting_name']
			: self::CONNECTOR_OPENAI_OPTION;
		$key_source      = self::connector_api_key_source( self::OPENAI_CONNECTOR_ID, $setting_name );
		$is_registered   = function_exists( 'wp_is_connector_registered' )
			? wp_is_connector_registered( self::OPENAI_CONNECTOR_ID )
			: null !== $connector;
		$credentials_url = is_string( $authentication['credentials_url'] ?? null ) && '' !== $authentication['credentials_url']
			? $authentication['credentials_url']
			: null;
		$plugin_slug     = is_string( $plugin['slug'] ?? null ) && '' !== $plugin['slug']
			? $plugin['slug']
			: null;
		$label           = is_string( $connector['name'] ?? null ) && '' !== $connector['name']
			? $connector['name']
			: 'OpenAI';

		return [
			'registered'     => $is_registered,
			'configured'     => 'none' !== $key_source,
			'label'          => $label,
			'settingName'    => $setting_name,
			'keySource'      => $key_source,
			'credentialsUrl' => $credentials_url,
			'pluginSlug'     => $plugin_slug,
		];
	}

	/**
	 * Resolve the effective OpenAI native API key.
	 *
	 * Flavor Agent prefers its own saved key for backward compatibility, but will
	 * fall back to the core OpenAI connector lifecycle when the plugin-specific key
	 * is blank.
	 *
	 * @param array<string, string> $overrides
	 */
	public static function native_effective_api_key( array $overrides = [] ): string {
		return self::native_effective_api_key_metadata( $overrides )['api_key'];
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array{
	 *   api_key: string,
	 *   source: 'plugin_override'|'env'|'constant'|'connector_database'|'none',
	 *   connector: array{
	 *     registered: bool,
	 *     configured: bool,
	 *     label: string,
	 *     settingName: string,
	 *     keySource: 'env'|'constant'|'database'|'none',
	 *     credentialsUrl: ?string,
	 *     pluginSlug: ?string
	 *   }
	 * }
	 */
	public static function native_effective_api_key_metadata( array $overrides = [] ): array {
		if ( array_key_exists( 'flavor_agent_openai_native_api_key', $overrides ) ) {
			$api_key = (string) $overrides['flavor_agent_openai_native_api_key'];
		} else {
			$api_key = (string) get_option( 'flavor_agent_openai_native_api_key', '' );
		}

		$connector_status = self::openai_connector_status();

		if ( '' !== $api_key ) {
			return [
				'api_key'   => $api_key,
				'source'    => 'plugin_override',
				'connector' => $connector_status,
			];
		}

		$connector_source = $connector_status['keySource'];
		if ( 'none' !== $connector_source ) {
			return [
				'api_key'   => self::connector_api_key_value(
					self::OPENAI_CONNECTOR_ID,
					$connector_status['settingName'],
					$overrides
				),
				'source'    => 'database' === $connector_source ? 'connector_database' : $connector_source,
				'connector' => $connector_status,
			];
		}

		return [
			'api_key'   => '',
			'source'    => 'none',
			'connector' => $connector_status,
		];
	}

	/**
	 * @param array<string, string> $overrides
	 * @return 'plugin_override'|'env'|'constant'|'connector_database'|'none'
	 */
	public static function native_effective_api_key_source( array $overrides = [] ): string {
		return self::native_effective_api_key_metadata( $overrides )['source'];
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function chat_configuration( ?string $provider = null, array $overrides = [] ): array {
		$provider = self::normalize_provider( $provider ?? self::get() );

		if ( self::is_connector( $provider ) ) {
			$configured = WordPressAIClient::is_supported( $provider );

			return [
				'provider'   => $provider,
				'endpoint'   => '',
				'api_key'    => '',
				'model'      => $configured ? 'provider-managed' : '',
				'configured' => $configured,
				'headers'    => [],
				'url'        => '',
				'label'      => self::label( $provider ),
			];
		}

		if ( self::is_native( $provider ) ) {
			$api_key = self::native_effective_api_key( $overrides );
			$model   = self::option_value( $overrides, 'flavor_agent_openai_native_chat_model' );

			return [
				'provider'   => $provider,
				'endpoint'   => self::NATIVE_BASE_URL,
				'api_key'    => $api_key,
				'model'      => $model,
				'configured' => $api_key !== '' && $model !== '',
				'headers'    => self::native_headers( $api_key ),
				'url'        => self::NATIVE_BASE_URL . '/v1/responses',
				'label'      => 'OpenAI responses',
			];
		}

		$endpoint = self::option_value( $overrides, 'flavor_agent_azure_openai_endpoint' );
		$api_key  = self::option_value( $overrides, 'flavor_agent_azure_openai_key' );
		$model    = self::option_value( $overrides, 'flavor_agent_azure_chat_deployment' );

		return [
			'provider'   => $provider,
			'endpoint'   => $endpoint,
			'api_key'    => $api_key,
			'model'      => $model,
			'configured' => $endpoint !== '' && $api_key !== '' && $model !== '',
			'headers'    => self::azure_headers( $api_key ),
			'url'        => rtrim( $endpoint, '/' ) . '/openai/v1/responses',
			'label'      => 'Azure OpenAI responses',
		];
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function embedding_configuration( ?string $provider = null, array $overrides = [] ): array {
		$provider = self::normalize_provider( $provider ?? self::get() );

		if ( self::is_connector( $provider ) ) {
			return [
				'provider'   => $provider,
				'endpoint'   => '',
				'api_key'    => '',
				'model'      => '',
				'configured' => false,
				'headers'    => [],
				'url'        => '',
				'label'      => self::label( $provider ),
			];
		}

		if ( self::is_native( $provider ) ) {
			$api_key = self::native_effective_api_key( $overrides );
			$model   = self::option_value( $overrides, 'flavor_agent_openai_native_embedding_model' );

			return [
				'provider'   => $provider,
				'endpoint'   => self::NATIVE_BASE_URL,
				'api_key'    => $api_key,
				'model'      => $model,
				'configured' => $api_key !== '' && $model !== '',
				'headers'    => self::native_headers( $api_key ),
				'url'        => self::NATIVE_BASE_URL . '/v1/embeddings',
				'label'      => 'OpenAI embeddings',
			];
		}

		$endpoint = self::option_value( $overrides, 'flavor_agent_azure_openai_endpoint' );
		$api_key  = self::option_value( $overrides, 'flavor_agent_azure_openai_key' );
		$model    = self::option_value( $overrides, 'flavor_agent_azure_embedding_deployment' );

		return [
			'provider'   => $provider,
			'endpoint'   => $endpoint,
			'api_key'    => $api_key,
			'model'      => $model,
			'configured' => $endpoint !== '' && $api_key !== '' && $model !== '',
			'headers'    => self::azure_headers( $api_key ),
			'url'        => rtrim( $endpoint, '/' ) . '/openai/v1/embeddings',
			'label'      => 'Azure OpenAI embeddings',
		];
	}

	public static function chat_configured(): bool {
		return self::chat_configuration()['configured'];
	}

	public static function embedding_configured(): bool {
		return self::embedding_configuration()['configured'];
	}

	public static function active_chat_model(): ?string {
		$model = trim( self::chat_configuration()['model'] );

		return $model !== '' ? $model : null;
	}

	public static function active_embedding_model(): ?string {
		$model = trim( self::embedding_configuration()['model'] );

		return $model !== '' ? $model : null;
	}

	public static function normalize_provider( string $provider ): string {
		$provider = sanitize_key( $provider );

		if ( isset( self::all_choices()[ $provider ] ) ) {
			return $provider;
		}

		return self::AZURE;
	}

	/**
	 * @return array<string, string>
	 */
	private static function all_choices(): array {
		return self::direct_choices() + self::registered_connector_choices();
	}

	/**
	 * @return array<string, string>
	 */
	private static function registered_connector_choices(): array {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return [];
		}

		$choices = [];

		foreach ( wp_get_connectors() as $provider => $connector ) {
			if ( ! is_string( $provider ) || ! is_array( $connector ) ) {
				continue;
			}

			if ( 'ai_provider' !== (string) ( $connector['type'] ?? '' ) ) {
				continue;
			}

			$provider = sanitize_key( $provider );
			if ( '' === $provider ) {
				continue;
			}

			$label = is_string( $connector['name'] ?? null ) && '' !== $connector['name']
				? $connector['name']
				: ucwords( str_replace( [ '-', '_' ], ' ', $provider ) );

			$choices[ $provider ] = $label;
		}

		return $choices;
	}

	/**
	 * @return array<string, string>
	 */
	private static function selectable_connector_choices( ?string $selected_provider = null ): array {
		$selected_provider = sanitize_key(
			(string) (
				$selected_provider
				?? get_option( self::OPTION_NAME, self::AZURE )
			)
		);
		$registered        = self::registered_connector_choices();
		$choices           = [];

		foreach ( $registered as $provider => $label ) {
			if ( WordPressAIClient::is_supported( $provider ) ) {
				$choices[ $provider ] = sprintf( '%s (Settings > Connectors)', $label );
			}
		}

		if (
			'' !== $selected_provider
			&& isset( $registered[ $selected_provider ] )
			&& ! isset( $choices[ $selected_provider ] )
		) {
			$choices[ $selected_provider ] = sprintf(
				'%s (Settings > Connectors, currently unavailable)',
				$registered[ $selected_provider ]
			);
		}

		return $choices;
	}

	/**
	 * @return array<string, string>
	 */
	private static function azure_headers( string $api_key ): array {
		return [
			'Content-Type' => 'application/json',
			'api-key'      => $api_key,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function native_headers( string $api_key ): array {
		return [
			'Content-Type'  => 'application/json',
			'Authorization' => $api_key !== '' ? 'Bearer ' . $api_key : '',
		];
	}

	/**
	 * @return array{
	 *   name: string,
	 *   description?: string,
	 *   logo_url?: string,
	 *   type: string,
	 *   authentication: array<string, string>,
	 *   plugin?: array<string, string>
	 * }|null
	 */
	private static function openai_connector(): ?array {
		if ( ! function_exists( 'wp_get_connector' ) ) {
			return null;
		}

		$connector = wp_get_connector( self::OPENAI_CONNECTOR_ID );

		return is_array( $connector ) ? $connector : null;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return 'env'|'constant'|'database'|'none'
	 */
	private static function connector_api_key_source( string $connector_id, string $setting_name, array $overrides = [] ): string {
		$env_var_name = self::connector_env_var_name( $connector_id );
		$env_value    = getenv( $env_var_name );
		if ( false !== $env_value && '' !== $env_value ) {
			return 'env';
		}

		if ( defined( $env_var_name ) ) {
			$constant_value = constant( $env_var_name );
			if ( is_scalar( $constant_value ) && '' !== (string) $constant_value ) {
				return 'constant';
			}
		}

		$db_value = self::option_value( $overrides, $setting_name );
		if ( '' !== $db_value ) {
			return 'database';
		}

		return 'none';
	}

	/**
	 * @param array<string, string> $overrides
	 */
	private static function connector_api_key_value( string $connector_id, string $setting_name, array $overrides = [] ): string {
		$source       = self::connector_api_key_source( $connector_id, $setting_name, $overrides );
		$env_var_name = self::connector_env_var_name( $connector_id );

		if ( 'env' === $source ) {
			$env_value = getenv( $env_var_name );

			return false !== $env_value ? (string) $env_value : '';
		}

		if ( 'constant' === $source ) {
			$constant_value = constant( $env_var_name );

			return is_scalar( $constant_value ) ? (string) $constant_value : '';
		}

		if ( 'database' === $source ) {
			return self::option_value( $overrides, $setting_name );
		}

		return '';
	}

	private static function connector_env_var_name( string $connector_id ): string {
		$constant_case_id = strtoupper(
			preg_replace( '/([a-z])([A-Z])/', '$1_$2', str_replace( '-', '_', $connector_id ) )
		);

		return "{$constant_case_id}_API_KEY";
	}

	/**
	 * @param array<string, string> $overrides
	 */
	private static function option_value( array $overrides, string $option_name ): string {
		if ( array_key_exists( $option_name, $overrides ) ) {
			return (string) $overrides[ $option_name ];
		}

		return (string) get_option( $option_name, '' );
	}
}
