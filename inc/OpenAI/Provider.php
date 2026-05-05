<?php

declare(strict_types=1);

namespace FlavorAgent\OpenAI;

use FlavorAgent\Cloudflare\WorkersAIEmbeddingConfiguration;
use FlavorAgent\LLM\WordPressAIClient;

/**
 * Legacy-named provider facade for chat metadata and Workers AI embeddings.
 *
 * The OpenAI namespace and `flavor_agent_openai_provider` option are historical
 * compatibility surfaces. Runtime chat is resolved through the WordPress AI
 * Client, and plugin-owned embeddings resolve to Cloudflare Workers AI only.
 */
final class Provider {


	public const OPTION_NAME = 'flavor_agent_openai_provider';

	private const WORDPRESS_AI_CLIENT_PROVIDER = 'wordpress_ai_client';

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
			WorkersAIEmbeddingConfiguration::PROVIDER => 'Cloudflare Workers AI',
		];
	}

	/**
	 * @return array<string, string>
	 */
	public static function choices( ?string $selected_provider = null ): array {
		unset( $selected_provider );

		return self::direct_choices();
	}

	public static function get(): string {
		return WorkersAIEmbeddingConfiguration::PROVIDER;
	}

	public static function label( ?string $provider = null ): string {
		$provider = sanitize_key( (string) ( $provider ?? self::get() ) );
		$choices  = self::all_choices();

		if ( isset( $choices[ $provider ] ) ) {
			return $choices[ $provider ];
		}

		return self::direct_choices()[ WorkersAIEmbeddingConfiguration::PROVIDER ];
	}

	public static function is_connector( ?string $provider = null ): bool {
		$provider = sanitize_key( (string) ( $provider ?? self::get() ) );

		return isset( self::registered_connector_choices()[ $provider ] );
	}

	public static function is_saved_legacy_connector_pin( ?string $provider = null ): bool {
		unset( $provider );

		return false;
	}

	public static function is_connector_or_saved_legacy_pin( ?string $provider = null ): bool {
		$provider = sanitize_key( (string) ( $provider ?? self::get() ) );

		return self::is_connector( $provider ) || self::is_saved_legacy_connector_pin( $provider );
	}

	public static function legacy_connector_pin_label( string $provider ): string {
		$provider          = sanitize_key( $provider );
		$connector_choices = self::registered_connector_choices();

		if ( isset( $connector_choices[ $provider ] ) ) {
			return $connector_choices[ $provider ];
		}

		return '' !== $provider ? $provider : self::direct_choices()[ WorkersAIEmbeddingConfiguration::PROVIDER ];
	}

	public static function is_wordpress_ai_client( ?string $provider = null ): bool {
		return sanitize_key( (string) ( $provider ?? self::get() ) ) === self::WORDPRESS_AI_CLIENT_PROVIDER;
	}

	/**
	 * Chat is owned by Settings > Connectors via the WordPress AI Client. Flavor
	 * Agent ignores saved provider values from older settings screens and lets the
	 * configured generic WordPress AI Client runtime choose text generation.
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

		if (
			'' !== $connector_provider &&
			self::is_connector( $connector_provider ) &&
			WordPressAIClient::is_supported( $connector_provider )
		) {
			return self::connector_chat_configuration( $connector_provider );
		}

		if ( self::is_saved_legacy_connector_pin( $provider ) ) {
			return self::missing_chat_configuration( $provider );
		}

		if ( ! self::is_connector( $provider ) && WordPressAIClient::is_supported() ) {
			return self::wordpress_ai_client_configuration();
		}

		return self::missing_chat_configuration( $provider );
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function embedding_configuration( ?string $provider = null, array $overrides = [] ): array {
		unset( $provider );

		return WorkersAIEmbeddingConfiguration::get( $overrides );
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

	/**
	 * @return array{provider: string, providerLabel: string, backendLabel: string, model: string, configured: bool, owner: string, ownerLabel: string, pathLabel: string}
	 */
	public static function active_embedding_request_meta(): array {
		$config         = self::embedding_configuration();
		$provider       = sanitize_key( (string) ( $config['provider'] ?? self::get() ) );
		$provider_label = self::label( $provider );
		$model          = trim( (string) ( $config['model'] ?? '' ) );
		$backend_label  = trim( (string) ( $config['label'] ?? $provider_label ) );

		return [
			'provider'      => $provider,
			'providerLabel' => $provider_label,
			'backendLabel'  => '' !== $backend_label ? $backend_label : $provider_label,
			'model'         => '' !== $model ? $model : 'not configured',
			'configured'    => ! empty( $config['configured'] ),
			'owner'         => 'flavor_agent',
			'ownerLabel'    => 'Settings > Flavor Agent',
			'pathLabel'     => sprintf( '%s pattern embeddings', $provider_label ),
		];
	}

	public static function normalize_provider( string $provider ): string {
		$provider = sanitize_key( $provider );

		if ( isset( self::all_choices()[ $provider ] ) ) {
			return $provider;
		}

		return WorkersAIEmbeddingConfiguration::PROVIDER;
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

		if ( ! self::is_connector_or_saved_legacy_pin( $provider ) ) {
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
	 * Resolve the active chat runtime. Chat belongs to Settings > Connectors. A
	 * Saved provider values do not pin chat. Flavor Agent uses the configured
	 * WordPress AI Client runtime independently of the embedding model configured
	 * on this page.
	 *
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	private static function runtime_chat_configuration(): array {
		$selected_provider  = self::get();
		$connector_provider = self::selected_chat_connector( $selected_provider );

		if ( WorkersAIEmbeddingConfiguration::PROVIDER === $selected_provider && WordPressAIClient::is_supported() ) {
			return self::wordpress_ai_client_configuration();
		}

		if ( '' !== $connector_provider && self::is_connector( $connector_provider ) && WordPressAIClient::is_supported( $connector_provider ) ) {
			return self::connector_chat_configuration( $connector_provider );
		}

		if ( ! self::is_connector( $selected_provider ) && WordPressAIClient::is_supported() ) {
			return self::wordpress_ai_client_configuration();
		}

		return self::missing_chat_configuration( $selected_provider );
	}

	private static function selected_chat_connector( string $provider ): string {
		$provider = self::normalize_provider( $provider );

		if ( self::is_connector_or_saved_legacy_pin( $provider ) ) {
			return $provider;
		}

		return '';
	}

	private static function chat_provider_matches_selection( string $selected_provider, string $provider ): bool {
		$selected_provider = self::normalize_provider( $selected_provider );
		$provider          = self::normalize_provider_for_request_meta( $provider );

		if ( $provider === $selected_provider ) {
			return true;
		}

		if (
			WorkersAIEmbeddingConfiguration::PROVIDER === $selected_provider
			&& self::WORDPRESS_AI_CLIENT_PROVIDER === $provider
		) {
			return true;
		}

		if (
			self::WORDPRESS_AI_CLIENT_PROVIDER === $provider
			&& ! self::is_connector_or_saved_legacy_pin( $selected_provider )
		) {
			return true;
		}

		return false;
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
		return WorkersAIEmbeddingConfiguration::get( $overrides );
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
}
