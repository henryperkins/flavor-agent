<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

use FlavorAgent\Support\FlavorAgentRequestTag;
use FlavorAgent\Support\RequestTrace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestLoggingBridge {

	private const EXPERIMENT_OPTION = 'wpai_feature_ai-request-logging_enabled';
	private const MASTER_OPTION     = 'wpai_features_enabled';
	private const CAPTURED_LOG_CAP  = 50;

	/**
	 * @var array<string, string>
	 */
	private static array $captured_log_ids = [];

	public static function register(): void {
		if ( ! self::is_core_logging_class_available() ) {
			return;
		}

		\add_filter( 'wpai_request_log_context', [ self::class, 'inject_flavor_agent_context' ], 10, 3 );
		\add_action( 'wpai_request_logged', [ self::class, 'capture_log_id' ], 10, 2 );
	}

	public static function is_core_logging_class_available(): bool {
		$available = \class_exists( '\WordPress\AI\Logging\AI_Request_Log_Manager' )
			&& \class_exists( '\WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging' );

		/**
		 * Filters whether the WordPress AI Request Logging classes are available.
		 *
		 * This keeps the bridge testable without loading the AI plugin in PHPUnit.
		 */
		return (bool) \apply_filters( 'flavor_agent_core_request_logging_class_available', $available );
	}

	public static function is_core_logging_enabled(): bool {
		if ( ! self::is_core_logging_class_available() ) {
			return false;
		}

		$master_enabled = (bool) \apply_filters(
			self::MASTER_OPTION,
			(bool) \get_option( self::MASTER_OPTION, false )
		);

		if ( ! $master_enabled ) {
			return false;
		}

		return (bool) \apply_filters(
			self::EXPERIMENT_OPTION,
			(bool) \get_option( self::EXPERIMENT_OPTION, false )
		);
	}

	public static function should_persist_request_diagnostic(): bool {
		if ( ! self::is_core_logging_enabled() ) {
			return true;
		}

		return (bool) \apply_filters( 'flavor_agent_persist_request_diagnostic_with_core_logging', false );
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $decoded
	 * @param array<string, mixed> $log_data
	 * @return array<string, mixed>
	 */
	public static function inject_flavor_agent_context( array $context, array $decoded, array $log_data ): array {
		unset( $decoded, $log_data );

		$tag = FlavorAgentRequestTag::current();
		if ( ! $tag instanceof FlavorAgentRequestTag ) {
			return $context;
		}

		$source_slug = \sanitize_key( (string) ( $context['source']['slug'] ?? '' ) );
		if ( 'flavor-agent' !== $source_slug ) {
			return $context;
		}

		$context['flavor_agent'] = [
			'surface'       => \sanitize_key( $tag->surface() ),
			'abilityName'   => \sanitize_text_field( $tag->ability_name() ),
			'scopeKey'      => \sanitize_text_field( $tag->scope_key() ),
			'documentRef'   => Serializer::normalize_structured_value( $tag->document_ref() ),
			'requestToken'  => \sanitize_text_field( $tag->request_token() ),
			'pluginVersion' => \defined( 'FLAVOR_AGENT_VERSION' ) ? FLAVOR_AGENT_VERSION : 'unknown',
		];

		return $context;
	}

	/**
	 * @param array<string, mixed> $insert_data
	 */
	public static function capture_log_id( string $log_id, array $insert_data ): void {
		$log_id = \sanitize_text_field( $log_id );
		if ( '' === $log_id ) {
			return;
		}

		$context = $insert_data['context'] ?? null;
		if ( \is_string( $context ) ) {
			$decoded = \json_decode( $context, true );
			$context = \is_array( $decoded ) ? $decoded : null;
		}

		if ( ! \is_array( $context ) ) {
			return;
		}

		$flavor_agent = $context['flavor_agent'] ?? null;
		if ( ! \is_array( $flavor_agent ) ) {
			return;
		}

		$request_token = \sanitize_text_field( (string) ( $flavor_agent['requestToken'] ?? '' ) );
		if ( '' === $request_token ) {
			return;
		}

		unset( self::$captured_log_ids[ $request_token ] );
		self::$captured_log_ids[ $request_token ] = $log_id;
		self::enforce_capture_cap();

		RequestTrace::event(
			'ai.chat.log_id_captured',
			[
				'logId'        => $log_id,
				'requestToken' => $request_token,
			]
		);
	}

	public static function consume_log_id( string $request_token ): ?string {
		$request_token = \sanitize_text_field( $request_token );
		if ( '' === $request_token || ! isset( self::$captured_log_ids[ $request_token ] ) ) {
			return null;
		}

		$log_id = self::$captured_log_ids[ $request_token ];
		unset( self::$captured_log_ids[ $request_token ] );

		return $log_id;
	}

	private static function enforce_capture_cap(): void {
		while ( \count( self::$captured_log_ids ) > self::CAPTURED_LOG_CAP ) {
			$keys = \array_keys( self::$captured_log_ids );
			unset( self::$captured_log_ids[ (string) $keys[0] ] );
		}
	}
}
