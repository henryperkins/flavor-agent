<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestTrace {

	private const ACTION_HOOK       = 'flavor_agent_diagnostic_trace';
	private const ENABLED_FILTER    = 'flavor_agent_diagnostic_trace_enabled';
	private const LOG_PREFIX        = 'Flavor Agent diagnostic trace ';
	private const MAX_CONTEXT_DEPTH = 4;
	private const MAX_ARRAY_ITEMS   = 30;
	private const MAX_STRING_BYTES  = 300;

	/**
	 * @var array{traceId?: string, surface?: string, startedAt?: float, finished?: bool}
	 */
	private static array $active = [];

	private static bool $shutdown_registered = false;

	public static function is_active(): bool {
		return isset( self::$active['traceId'] ) && is_string( self::$active['traceId'] );
	}

	public static function is_consumed(): bool {
		if ( function_exists( 'has_action' ) && false !== has_action( self::ACTION_HOOK ) ) {
			return true;
		}

		return self::should_write_error_log();
	}

	/**
	 * @return array{throwable: array{class: string, message: string, file: string, line: int}}
	 */
	public static function throwable_context( \Throwable $throwable ): array {
		return [
			'throwable' => [
				'class'   => get_class( $throwable ),
				'message' => $throwable->getMessage(),
				'file'    => $throwable->getFile(),
				'line'    => $throwable->getLine(),
			],
		];
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function start( string $surface, array $context = [], string $event = 'trace.start' ): string {
		self::$active = [
			'traceId'   => self::generate_trace_id(),
			'surface'   => self::sanitize_key_value( $surface ),
			'startedAt' => microtime( true ),
			'finished'  => false,
		];

		self::register_shutdown_logger();
		self::event(
			$event,
			array_merge(
				[ 'limits' => self::runtime_limits() ],
				$context
			)
		);

		return (string) self::$active['traceId'];
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function event( string $event, array $context = [] ): void {
		if ( ! self::is_active() ) {
			return;
		}

		$started_at = is_float( self::$active['startedAt'] ?? null ) ? self::$active['startedAt'] : microtime( true );
		$entry      = [
			'traceId'   => (string) self::$active['traceId'],
			'event'     => self::sanitize_event( $event ),
			'surface'   => (string) ( self::$active['surface'] ?? '' ),
			'timestamp' => gmdate( 'c' ),
			'elapsedMs' => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
			'runtime'   => self::runtime_snapshot(),
		];

		if ( [] !== $context ) {
			$entry['context'] = self::sanitize_context( $context );
		}

		self::emit( $entry );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function finish( string $event = 'trace.finish', array $context = [] ): void {
		if ( ! self::is_active() ) {
			return;
		}

		self::event( $event, $context );
		self::$active['finished'] = true;
		self::$active             = [];
	}

	private static function register_shutdown_logger(): void {
		if ( self::$shutdown_registered ) {
			return;
		}

		self::$shutdown_registered = true;

		register_shutdown_function(
			static function (): void {
				if ( ! self::is_active() ) {
					return;
				}

				$error   = error_get_last();
				$context = [ 'finished' => false ];

				if ( is_array( $error ) && self::is_fatal_error_type( $error['type'] ?? null ) ) {
					$context['fatalError'] = [
						'type'    => (int) ( $error['type'] ?? 0 ),
						'message' => is_string( $error['message'] ?? null ) ? $error['message'] : '',
						'file'    => is_string( $error['file'] ?? null ) ? $error['file'] : '',
						'line'    => is_numeric( $error['line'] ?? null ) ? (int) $error['line'] : 0,
					];
				}

				self::event( 'trace.shutdown', $context );
				self::$active = [];
			}
		);
	}

	private static function is_fatal_error_type( mixed $type ): bool {
		if ( ! is_numeric( $type ) ) {
			return false;
		}

		return in_array(
			(int) $type,
			[
				E_ERROR,
				E_PARSE,
				E_CORE_ERROR,
				E_COMPILE_ERROR,
				E_USER_ERROR,
				E_RECOVERABLE_ERROR,
			],
			true
		);
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function emit( array $entry ): void {
		if ( function_exists( 'do_action' ) ) {
			try {
				do_action( self::ACTION_HOOK, $entry );
			} catch ( \Throwable ) {
				// Diagnostic observers must not change request behavior.
			}
		}

		if ( ! self::should_write_error_log() ) {
			return;
		}

		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $entry )
			: json_encode( $entry );

		if ( is_string( $encoded ) && '' !== $encoded ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Explicitly gated diagnostic trace for request failures.
			error_log( self::LOG_PREFIX . $encoded );
		}
	}

	private static function should_write_error_log(): bool {
		$enabled = defined( 'FLAVOR_AGENT_ENABLE_DIAGNOSTIC_TRACE' ) && FLAVOR_AGENT_ENABLE_DIAGNOSTIC_TRACE;

		if ( function_exists( 'apply_filters' ) ) {
			try {
				$enabled = (bool) apply_filters( self::ENABLED_FILTER, $enabled );
			} catch ( \Throwable ) {
				return false;
			}
		}

		return $enabled;
	}

	/**
	 * @return array{maxExecutionTime: string, memoryLimit: string}
	 */
	private static function runtime_limits(): array {
		return [
			'maxExecutionTime' => (string) ini_get( 'max_execution_time' ),
			'memoryLimit'      => (string) ini_get( 'memory_limit' ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function runtime_snapshot(): array {
		$headers_file = '';
		$headers_line = 0;
		$headers_sent = headers_sent( $headers_file, $headers_line );
		$buffer_bytes = ob_get_length();
		$runtime      = [
			'memoryBytes'     => memory_get_usage( true ),
			'peakMemoryBytes' => memory_get_peak_usage( true ),
			'outputBuffers'   => ob_get_level(),
			'headersSent'     => $headers_sent,
		];

		if ( false !== $buffer_bytes ) {
			$runtime['outputBufferBytes'] = $buffer_bytes;
		}

		if ( $headers_sent ) {
			$runtime['headersSentAt'] = [
				'file' => $headers_file,
				'line' => $headers_line,
			];
		}

		return $runtime;
	}

	private static function generate_trace_id(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable ) {
			return str_replace( '.', '', uniqid( '', true ) );
		}
	}

	private static function sanitize_key_value( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $value );
		}

		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ) ?? '';
	}

	private static function sanitize_event( string $event ): string {
		$event = preg_replace( '/[^A-Za-z0-9_.-]/', '', $event ) ?? '';

		return '' !== $event ? $event : 'trace.event';
	}

	private static function sanitize_context( mixed $value, int $depth = 0 ): mixed {
		if ( $depth >= self::MAX_CONTEXT_DEPTH ) {
			return is_array( $value ) || is_object( $value ) ? '[truncated]' : self::sanitize_scalar( $value );
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			return self::sanitize_scalar( $value );
		}

		if ( is_object( $value ) ) {
			return [ 'class' => get_class( $value ) ];
		}

		if ( ! is_array( $value ) ) {
			return gettype( $value );
		}

		$normalized = [];
		$count      = 0;

		foreach ( $value as $key => $item ) {
			if ( $count >= self::MAX_ARRAY_ITEMS ) {
				$normalized['_truncated'] = count( $value ) - self::MAX_ARRAY_ITEMS;
				break;
			}

			$normalized[ self::sanitize_context_key( $key ) ] = self::sanitize_context( $item, $depth + 1 );
			++$count;
		}

		return $normalized;
	}

	private static function sanitize_context_key( int|string $key ): int|string {
		if ( is_int( $key ) ) {
			return $key;
		}

		return substr( $key, 0, 80 );
	}

	private static function sanitize_scalar( mixed $value ): string {
		$value = trim( (string) $value );
		$value = preg_replace( '/[[:cntrl:]]+/', ' ', $value ) ?? '';

		if ( strlen( $value ) <= self::MAX_STRING_BYTES ) {
			return $value;
		}

		return substr( $value, 0, self::MAX_STRING_BYTES ) . '...';
	}
}
