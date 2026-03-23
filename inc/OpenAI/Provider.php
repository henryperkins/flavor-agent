<?php

declare(strict_types=1);

namespace FlavorAgent\OpenAI;

final class Provider {

	public const OPTION_NAME = 'flavor_agent_openai_provider';
	public const AZURE       = 'azure_openai';
	public const NATIVE      = 'openai_native';

	private const CONNECTOR_OPENAI_OPTION = 'connectors_ai_openai_api_key';
	private const NATIVE_API_KEY_ENV_VAR  = 'OPENAI_API_KEY';
	private const NATIVE_BASE_URL         = 'https://api.openai.com';

	/**
	 * @return array<string, string>
	 */
	public static function choices(): array {
		return [
			self::AZURE  => 'Azure OpenAI',
			self::NATIVE => 'OpenAI Native',
		];
	}

	public static function get(): string {
		$provider = sanitize_key( (string) get_option( self::OPTION_NAME, self::AZURE ) );

		if ( isset( self::choices()[ $provider ] ) ) {
			return $provider;
		}

		return self::AZURE;
	}

	public static function label( ?string $provider = null ): string {
		$provider = $provider ?? self::get();
		$choices  = self::choices();

		return $choices[ $provider ] ?? $choices[ self::AZURE ];
	}

	public static function is_azure( ?string $provider = null ): bool {
		return ( $provider ?? self::get() ) === self::AZURE;
	}

	public static function is_native( ?string $provider = null ): bool {
		return ( $provider ?? self::get() ) === self::NATIVE;
	}

	public static function native_base_url(): string {
		return self::NATIVE_BASE_URL;
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
		if ( array_key_exists( 'flavor_agent_openai_native_api_key', $overrides ) ) {
			$api_key = (string) $overrides['flavor_agent_openai_native_api_key'];
		} else {
			$api_key = (string) get_option( 'flavor_agent_openai_native_api_key', '' );
		}

		if ( '' !== $api_key ) {
			return $api_key;
		}

		$api_key = getenv( self::NATIVE_API_KEY_ENV_VAR );
		if ( false !== $api_key && '' !== $api_key ) {
			return (string) $api_key;
		}

		if ( defined( self::NATIVE_API_KEY_ENV_VAR ) ) {
			$constant_value = constant( self::NATIVE_API_KEY_ENV_VAR );
			if ( is_scalar( $constant_value ) && '' !== (string) $constant_value ) {
				return (string) $constant_value;
			}
		}

		return (string) get_option( self::CONNECTOR_OPENAI_OPTION, '' );
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array{provider: string, endpoint: string, api_key: string, model: string, configured: bool, headers: array<string, string>, url: string, label: string}
	 */
	public static function chat_configuration( ?string $provider = null, array $overrides = [] ): array {
		$provider = self::normalize_provider( $provider ?? self::get() );

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

		if ( isset( self::choices()[ $provider ] ) ) {
			return $provider;
		}

		return self::AZURE;
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
	 * @param array<string, string> $overrides
	 */
	private static function option_value( array $overrides, string $option_name ): string {
		if ( array_key_exists( $option_name, $overrides ) ) {
			return (string) $overrides[ $option_name ];
		}

		return (string) get_option( $option_name, '' );
	}
}
