<?php

declare(strict_types=1);

namespace FlavorAgent\OpenAI;

use FlavorAgent\LLM\WordPressAIClient;

final class Provider {

	public const OPTION_NAME = 'flavor_agent_openai_provider';
	public const AZURE       = 'azure_openai';
	public const NATIVE      = 'openai_native';

	private const WORDPRESS_AI_CLIENT_PROVIDER = 'wordpress_ai_client';
	private const OPENAI_CONNECTOR_ID          = 'openai';
	private const CONNECTOR_OPENAI_OPTION      = 'connectors_ai_openai_api_key';
	private const NATIVE_API_KEY_ENV_VAR       = 'OPENAI_API_KEY';
	private const NATIVE_BASE_URL              = 'https://api.openai.com';

	/**
	 * @var array<string, mixed>|null
	 */
	private static ?array $last_runtime_chat_configuration = null;

	private static bool $has_fresh_runtime_chat_configuration = false;

	/**
	 * @var array<string, mixed>|null
	 */
	private static ?array $last_runtime_chat_metrics = null;

	private static bool $has_fresh_runtime_chat_metrics = false;

	/**
	 * @var array<string, mixed>|null
	 */
	private static ?array $last_runtime_chat_diagnostics = null;

	private static bool $has_fresh_runtime_chat_diagnostics = false;

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

	public static function is_wordpress_ai_client( ?string $provider = null ): bool {
		return sanitize_key( (string) ( $provider ?? self::get() ) ) === self::WORDPRESS_AI_CLIENT_PROVIDER;
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
	 *   keySource: 'env'|'constant'|'database'|'connector_filter'|'none',
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
		$key_source      = self::connector_api_key_source( self::OPENAI_CONNECTOR_ID, $setting_name, [], $connector );
		$is_registered   = self::is_connector_registered( self::OPENAI_CONNECTOR_ID, $connector );
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
	 *     keySource: 'env'|'constant'|'database'|'connector_filter'|'none',
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
		if ( 'none' !== $connector_source && 'connector_filter' !== $connector_source ) {
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
	 * Chat is owned by Settings > Connectors via the WordPress AI Client. Flavor
	 * Agent only routes chat to the selected connector, or to the OpenAI connector
	 * when OpenAI Native is selected for embeddings. Other generic Connector
	 * providers are never used as fallbacks.
	 *
	 * @param array<string, string> $overrides Reserved for parity with embedding_configuration().
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function chat_configuration( ?string $provider = null, array $overrides = [] ): array {
		unset( $overrides );

		if ( null === $provider ) {
			$config                                     = self::runtime_chat_configuration();
			self::$last_runtime_chat_configuration      = $config;
			self::$has_fresh_runtime_chat_configuration = true;

			return $config;
		}

		$provider = self::normalize_provider( $provider );

		$connector_provider = self::selected_chat_connector( $provider );

		if ( '' !== $connector_provider ) {
			return self::connector_chat_configuration( $connector_provider );
		}

		return self::missing_chat_configuration( $provider );
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function embedding_configuration( ?string $provider = null, array $overrides = [] ): array {
		if ( null === $provider ) {
			return self::runtime_embedding_configuration( $overrides );
		}

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

	/**
	 * @return array{
	 *   selectedProvider: string,
	 *   selectedProviderLabel: string,
	 *   connectorId: string,
	 *   connectorLabel: string,
	 *   connectorPluginSlug: string,
	 *   provider: string,
	 *   providerLabel: string,
	 *   backendLabel: string,
	 *   model: string,
	 *   owner: string,
	 *   ownerLabel: string,
	 *   pathLabel: string,
	 *   credentialSource: string,
	 *   credentialSourceLabel: string,
	 *   transport?: array<string, mixed>,
	 *   requestSummary?: array<string, mixed>,
	 *   responseSummary?: array<string, mixed>,
	 *   errorSummary?: array<string, mixed>,
	 *   usedFallback: bool
	 * }
	 */
	public static function active_chat_request_meta(): array {
		$selected_provider                          = self::get();
		$config                                     = (
			self::$has_fresh_runtime_chat_configuration
			&& is_array( self::$last_runtime_chat_configuration )
		)
			? self::$last_runtime_chat_configuration
			: self::chat_configuration();
		self::$has_fresh_runtime_chat_configuration = false;
		$provider                                   = self::normalize_provider_for_request_meta(
			(string) ( $config['provider'] ?? $selected_provider )
		);
		$provider_label                             = self::provider_label_for_request_meta( $provider );
		$connector_meta                             = self::connector_meta_for_request_meta( $provider );
		$metrics                                    = self::active_chat_metrics();
		$diagnostics                                = self::active_chat_diagnostics();
		$backend_label                              = trim( (string) ( $config['label'] ?? $provider_label ) );
		$model                                      = trim( (string) ( $config['model'] ?? '' ) );
		$used_fallback                              = ! self::chat_provider_matches_selection( $selected_provider, $provider );
		$owner                                      = 'flavor_agent';
		$owner_label                                = 'Settings > Flavor Agent';
		$path_label                                 = 'Flavor Agent chat backend';
		$credential_source                          = 'plugin_settings';
		$credential_label                           = 'Settings > Flavor Agent';

		if ( self::is_connector( $provider ) ) {
			$owner             = 'connectors';
			$owner_label       = 'Settings > Connectors';
			$path_label        = sprintf( '%s via Settings > Connectors', $provider_label );
			$credential_source = 'provider_managed';
			$credential_label  = 'Provider-managed';
		} elseif ( self::is_wordpress_ai_client( $provider ) ) {
			$owner             = 'connectors';
			$owner_label       = 'Settings > Connectors';
			$path_label        = 'WordPress AI Client via Settings > Connectors';
			$credential_source = 'provider_managed';
			$credential_label  = 'Provider-managed';
		} else {
			$owner             = 'connectors';
			$owner_label       = 'Settings > Connectors';
			$path_label        = sprintf( '%s has no selected chat connector', $provider_label );
			$credential_source = 'provider_managed';
			$credential_label  = 'Provider-managed';
		}

		$meta = [
			'selectedProvider'      => $selected_provider,
			'selectedProviderLabel' => self::provider_label_for_request_meta( $selected_provider ),
			'connectorId'           => $connector_meta['id'],
			'connectorLabel'        => $connector_meta['label'],
			'connectorPluginSlug'   => $connector_meta['pluginSlug'],
			'provider'              => $provider,
			'providerLabel'         => $provider_label,
			'backendLabel'          => '' !== $backend_label ? $backend_label : $provider_label,
			'model'                 => '' !== $model ? $model : 'provider-managed',
			'owner'                 => $owner,
			'ownerLabel'            => $owner_label,
			'pathLabel'             => $path_label,
			'credentialSource'      => $credential_source,
			'credentialSourceLabel' => $credential_label,
			'tokenUsage'            => $metrics['tokenUsage'],
			'latencyMs'             => $metrics['latencyMs'],
			'usedFallback'          => $used_fallback,
		];

		foreach ( [ 'transport', 'requestSummary', 'responseSummary', 'errorSummary' ] as $key ) {
			if ( is_array( $diagnostics[ $key ] ?? null ) && [] !== $diagnostics[ $key ] ) {
				$meta[ $key ] = $diagnostics[ $key ];
			}
		}

		return $meta;
	}

	/**
	 * @param array<string, mixed>|null $metrics
	 */
	public static function record_runtime_chat_metrics( ?array $metrics ): void {
		$normalized = self::normalize_runtime_chat_metrics( $metrics );

		self::$last_runtime_chat_metrics      = null !== $normalized
			? $normalized
			: null;
		self::$has_fresh_runtime_chat_metrics = true;
	}

	/**
	 * @param array<string, mixed>|null $diagnostics
	 */
	public static function record_runtime_chat_diagnostics( ?array $diagnostics ): void {
		$normalized = self::normalize_runtime_chat_diagnostics( $diagnostics );

		self::$last_runtime_chat_diagnostics      = null !== $normalized
			? $normalized
			: null;
		self::$has_fresh_runtime_chat_diagnostics = true;
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

	private static function normalize_provider_for_request_meta( string $provider ): string {
		$provider = sanitize_key( $provider );

		if ( self::is_wordpress_ai_client( $provider ) ) {
			return $provider;
		}

		return self::normalize_provider( $provider );
	}

	private static function provider_label_for_request_meta( string $provider ): string {
		if ( self::is_wordpress_ai_client( $provider ) ) {
			return 'WordPress AI Client';
		}

		return self::label( $provider );
	}

	/**
	 * @return array{id: string, label: string, pluginSlug: string}
	 */
	private static function connector_meta_for_request_meta( string $provider ): array {
		if ( self::is_wordpress_ai_client( $provider ) ) {
			return [
				'id'         => self::WORDPRESS_AI_CLIENT_PROVIDER,
				'label'      => 'WordPress AI Client',
				'pluginSlug' => '',
			];
		}

		if ( ! self::is_connector( $provider ) ) {
			return [
				'id'         => '',
				'label'      => '',
				'pluginSlug' => '',
			];
		}

		$connector = self::registered_connectors()[ $provider ] ?? null;

		return [
			'id'         => $provider,
			'label'      => self::provider_label_for_request_meta( $provider ),
			'pluginSlug' => self::connector_plugin_slug_for_request_meta( $connector['plugin'] ?? null ),
		];
	}

	private static function connector_plugin_slug_for_request_meta( mixed $plugin ): string {
		if ( is_string( $plugin ) ) {
			return self::normalize_connector_plugin_slug( $plugin );
		}

		if ( ! is_array( $plugin ) ) {
			return '';
		}

		foreach ( [ 'slug', 'pluginSlug', 'plugin_slug', 'file', 'pluginFile', 'basename', 'path' ] as $key ) {
			$slug = self::normalize_connector_plugin_slug( $plugin[ $key ] ?? null );

			if ( '' !== $slug ) {
				return $slug;
			}
		}

		return '';
	}

	private static function normalize_connector_plugin_slug( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value = str_replace( '\\', '/', $value );
		$parts = array_values( array_filter( explode( '/', $value ), 'strlen' ) );
		$slug  = $parts[0] ?? $value;

		if ( str_ends_with( $slug, '.php' ) ) {
			$slug = substr( $slug, 0, -4 );
		}

		return sanitize_key( $slug );
	}

	/**
	 * @return array{tokenUsage: array<string, int>, latencyMs: int}|array{tokenUsage: array<string, int>, latencyMs: null}
	 */
	private static function active_chat_metrics(): array {
		if ( self::$has_fresh_runtime_chat_metrics ) {
			self::$has_fresh_runtime_chat_metrics = false;

			return self::$last_runtime_chat_metrics ?? [
				'tokenUsage' => [],
				'latencyMs'  => null,
			];
		}

		return [
			'tokenUsage' => [],
			'latencyMs'  => null,
		];
	}

	/**
	 * @return array{
	 *   transport?: array<string, mixed>,
	 *   requestSummary?: array<string, mixed>,
	 *   responseSummary?: array<string, mixed>,
	 *   errorSummary?: array<string, mixed>
	 * }
	 */
	private static function active_chat_diagnostics(): array {
		if ( self::$has_fresh_runtime_chat_diagnostics ) {
			self::$has_fresh_runtime_chat_diagnostics = false;

			return self::$last_runtime_chat_diagnostics ?? [];
		}

		return [];
	}

	/**
	 * @param array<string, mixed>|null $metrics
	 * @return array{tokenUsage: array<string, int>, latencyMs: int}|null
	 */
	private static function normalize_runtime_chat_metrics( ?array $metrics ): ?array {
		if ( ! is_array( $metrics ) ) {
			return null;
		}

		$token_usage = [];

		if ( is_array( $metrics['tokenUsage'] ?? null ) ) {
			$total  = self::normalize_runtime_metric_int( $metrics['tokenUsage']['total'] ?? null );
			$input  = self::normalize_runtime_metric_int( $metrics['tokenUsage']['input'] ?? null );
			$output = self::normalize_runtime_metric_int( $metrics['tokenUsage']['output'] ?? null );

			if ( null !== $total ) {
				$token_usage['total'] = $total;
			}

			if ( null !== $input ) {
				$token_usage['input'] = $input;
			}

			if ( null !== $output ) {
				$token_usage['output'] = $output;
			}
		}

		$latency_ms = self::normalize_runtime_metric_int( $metrics['latencyMs'] ?? null );

		if ( [] === $token_usage && null === $latency_ms ) {
			return null;
		}

		return [
			'tokenUsage' => $token_usage,
			'latencyMs'  => null !== $latency_ms ? $latency_ms : null,
		];
	}

	/**
	 * @param array<string, mixed>|null $diagnostics
	 * @return array<string, array<string, mixed>>|null
	 */
	private static function normalize_runtime_chat_diagnostics( ?array $diagnostics ): ?array {
		if ( ! is_array( $diagnostics ) ) {
			return null;
		}

		$normalized = [];

		foreach ( [ 'transport', 'requestSummary', 'responseSummary', 'errorSummary' ] as $key ) {
			if ( is_array( $diagnostics[ $key ] ?? null ) && [] !== $diagnostics[ $key ] ) {
				$normalized[ $key ] = $diagnostics[ $key ];
			}
		}

		return [] !== $normalized ? $normalized : null;
	}

	private static function normalize_runtime_metric_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		if ( is_string( $value ) && '' !== trim( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
			$normalized = (int) $value;

			return $normalized >= 0 ? $normalized : null;
		}

		return null;
	}

	/**
	 * Resolve the active chat runtime. The selected option may pin chat to a
	 * specific connector. OpenAI Native maps to the OpenAI connector when that
	 * connector is available. No other Connectors-backed provider is used as a
	 * fallback.
	 *
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	private static function runtime_chat_configuration(): array {
		$selected_provider  = self::get();
		$connector_provider = self::selected_chat_connector( $selected_provider );

		if ( '' !== $connector_provider && WordPressAIClient::is_supported( $connector_provider ) ) {
			return self::connector_chat_configuration( $connector_provider );
		}

		return self::missing_chat_configuration( $selected_provider );
	}

	private static function selected_chat_connector( string $provider ): string {
		$provider = self::normalize_provider( $provider );

		if ( self::is_connector( $provider ) ) {
			return $provider;
		}

		if ( self::NATIVE === $provider && self::is_connector( self::OPENAI_CONNECTOR_ID ) ) {
			return self::OPENAI_CONNECTOR_ID;
		}

		return '';
	}

	private static function chat_provider_matches_selection( string $selected_provider, string $provider ): bool {
		$selected_provider = self::normalize_provider( $selected_provider );
		$provider          = self::normalize_provider_for_request_meta( $provider );

		if ( $provider === $selected_provider ) {
			return true;
		}

		return self::NATIVE === $selected_provider && self::OPENAI_CONNECTOR_ID === $provider;
	}

	/**
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	private static function connector_chat_configuration( string $provider ): array {
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

	/**
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	private static function missing_chat_configuration( string $provider ): array {
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

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	private static function runtime_embedding_configuration( array $overrides = [] ): array {
		$selected_provider = self::get();
		$selected_config   = self::embedding_configuration( $selected_provider, $overrides );

		if ( $selected_config['configured'] ) {
			return $selected_config;
		}

		foreach ( array_keys( self::direct_choices() ) as $candidate ) {
			if ( $candidate === $selected_provider ) {
				continue;
			}

			$candidate_config = self::embedding_configuration( $candidate, $overrides );

			if ( $candidate_config['configured'] ) {
				return $candidate_config;
			}
		}

		return $selected_config;
	}

	/**
	 * @return array<string, string>
	 */
	private static function registered_connector_choices(): array {
		$choices = [];

		foreach ( self::registered_connectors() as $provider => $connector ) {
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
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	private static function wordpress_ai_client_configuration(): array {
		return [
			'provider'   => self::WORDPRESS_AI_CLIENT_PROVIDER,
			'endpoint'   => '',
			'api_key'    => '',
			'model'      => 'provider-managed',
			'configured' => true,
			'headers'    => [],
			'url'        => '',
			'label'      => 'WordPress AI Client',
		];
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
		if ( function_exists( 'wp_get_connector' ) ) {
			try {
				$connector = wp_get_connector( self::OPENAI_CONNECTOR_ID );

				if ( is_array( $connector ) ) {
					return $connector;
				}
			} catch ( \Throwable $throwable ) {
				// Fall back to the bulk registry lookup below.
			}
		}

		$connector = self::registered_connectors()[ self::OPENAI_CONNECTOR_ID ] ?? null;

		return is_array( $connector ) ? $connector : null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function registered_connectors(): array {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return [];
		}

		try {
			$connectors = wp_get_connectors();
		} catch ( \Throwable $throwable ) {
			return [];
		}

		return is_array( $connectors ) ? $connectors : [];
	}

	private static function is_connector_registered( string $connector_id, ?array $connector = null ): bool {
		if ( function_exists( 'wp_is_connector_registered' ) ) {
			try {
				return wp_is_connector_registered( $connector_id );
			} catch ( \Throwable $throwable ) {
				// Fall back to any registry data we can still read below.
			}
		}

		if ( null !== $connector ) {
			return true;
		}

		return array_key_exists( $connector_id, self::registered_connectors() );
	}

	/**
	 * @param array<string, string> $overrides
	 * @return 'env'|'constant'|'database'|'connector_filter'|'none'
	 */
	private static function connector_api_key_source( string $connector_id, string $setting_name, array $overrides = [], ?array $connector = null ): string {
		foreach ( self::connector_env_var_names( $connector_id, $setting_name ) as $env_var_name ) {
			$env_value = getenv( $env_var_name );
			if ( false !== $env_value && '' !== $env_value ) {
				return 'env';
			}

			if ( defined( $env_var_name ) ) {
				$constant_value = constant( $env_var_name );
				if ( is_scalar( $constant_value ) && '' !== (string) $constant_value ) {
					return 'constant';
				}
			}
		}

		$db_value = self::option_value( $overrides, $setting_name );

		if ( '' !== $db_value ) {
			return 'database';
		}

		$connector ??= self::registered_connectors()[ $connector_id ] ?? [];
		$filtered    = apply_filters(
			'wpai_is_' . sanitize_key( $connector_id ) . '_connector_configured',
			false,
			is_array( $connector ) ? $connector : []
		);

		return true === (bool) $filtered ? 'connector_filter' : 'none';
	}

	/**
	 * @param array<string, string> $overrides
	 */
	private static function connector_api_key_value( string $connector_id, string $setting_name, array $overrides = [] ): string {
		$source = self::connector_api_key_source( $connector_id, $setting_name, $overrides );

		if ( 'env' === $source ) {
			$env_value = false;

			foreach ( self::connector_env_var_names( $connector_id, $setting_name ) as $env_var_name ) {
				$env_value = getenv( $env_var_name );

				if ( false !== $env_value && '' !== $env_value ) {
					break;
				}
			}

			return false !== $env_value ? (string) $env_value : '';
		}

		if ( 'constant' === $source ) {
			$constant_value = '';

			foreach ( self::connector_env_var_names( $connector_id, $setting_name ) as $env_var_name ) {
				if ( ! defined( $env_var_name ) ) {
					continue;
				}

				$constant_value = constant( $env_var_name );

				if ( is_scalar( $constant_value ) && '' !== (string) $constant_value ) {
					break;
				}
			}

			return is_scalar( $constant_value ) ? (string) $constant_value : '';
		}

		if ( 'database' === $source ) {
			return self::option_value( $overrides, $setting_name );
		}

		return '';
	}

	/**
	 * @return array<int, string>
	 */
	private static function connector_env_var_names( string $connector_id, string $setting_name = '' ): array {
		$names = [];

		if ( '' !== $setting_name ) {
			$names[] = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '_', $setting_name ) ?? '' );
		}

		$names[] = self::connector_env_var_name( $connector_id );

		return array_values(
			array_unique(
				array_filter(
					$names,
					static fn ( string $name ): bool => '' !== $name
				)
			)
		);
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
