<?php

declare(strict_types=1);

namespace FlavorAgent\Context;

final class BlockOperationValidator {

	public const CATALOG_VERSION                   = 1;
	public const INSERT_PATTERN                    = 'insert_pattern';
	public const REPLACE_BLOCK_WITH_PATTERN        = 'replace_block_with_pattern';
	public const ACTION_INSERT_BEFORE              = 'insert_before';
	public const ACTION_INSERT_AFTER               = 'insert_after';
	public const ACTION_REPLACE                    = 'replace';
	public const TARGET_BLOCK                      = 'block';
	public const ERROR_STRUCTURAL_ACTIONS_DISABLED = 'block_structural_actions_disabled';
	public const ERROR_MULTI_OPERATION_UNSUPPORTED = 'multi_operation_unsupported';
	public const ERROR_INVALID_OPERATION_PAYLOAD   = 'invalid_operation_payload';
	public const ERROR_UNKNOWN_OPERATION_TYPE      = 'unknown_operation_type';
	public const ERROR_MISSING_PATTERN_NAME        = 'missing_pattern_name';
	public const ERROR_PATTERN_NOT_AVAILABLE       = 'pattern_not_available';
	public const ERROR_MISSING_TARGET_CLIENT_ID    = 'missing_target_client_id';
	public const ERROR_STALE_TARGET                = 'stale_target';
	public const ERROR_CROSS_SURFACE_TARGET        = 'cross_surface_target';
	public const ERROR_INVALID_TARGET_TYPE         = 'invalid_target_type';
	public const ERROR_LOCKED_TARGET               = 'locked_target';
	public const ERROR_CONTENT_ONLY_TARGET         = 'content_only_target';
	public const ERROR_INVALID_INSERTION_POSITION  = 'invalid_insertion_position';
	public const ERROR_ACTION_NOT_ALLOWED          = 'action_not_allowed';

	private const ALLOWED_ACTIONS = [
		self::ACTION_INSERT_BEFORE,
		self::ACTION_INSERT_AFTER,
		self::ACTION_REPLACE,
	];

	/**
	 * @param array<string, mixed> $block_operation_context
	 * @return array<string, mixed>
	 */
	public static function normalize_context( array $block_operation_context, bool $enabled ): array {
		$editing_mode = self::normalize_string( $block_operation_context['editingMode'] ?? 'default' );

		return [
			'enableBlockStructuralActions' => $enabled,
			'targetClientId'               => self::normalize_string( $block_operation_context['targetClientId'] ?? '' ),
			'targetBlockName'              => self::normalize_string( $block_operation_context['targetBlockName'] ?? '' ),
			'targetSignature'              => self::normalize_string( $block_operation_context['targetSignature'] ?? '' ),
			'isTargetLocked'               => true === ( $block_operation_context['isTargetLocked'] ?? false ),
			'isContentOnly'                => true === ( $block_operation_context['isContentOnly'] ?? false ) || 'contentOnly' === $editing_mode,
			'editingMode'                  => '' !== $editing_mode ? $editing_mode : 'default',
			'allowedPatterns'              => self::normalize_allowed_patterns( $block_operation_context['allowedPatterns'] ?? [] ),
		];
	}

	/**
	 * @param array<int, mixed>    $operations
	 * @param array<string, mixed> $context
	 * @return array{ok: bool, catalogVersion: int, operations: array<int, array<string, mixed>>, rejectedOperations: array<int, array<string, mixed>>, proposedCount: int}
	 */
	public static function validate_sequence( array $operations, array $context ): array {
		$raw_operations = array_values( $operations );

		if ( 0 === count( $raw_operations ) ) {
			return self::validation_result( [], [], 0 );
		}

		if ( true !== ( $context['enableBlockStructuralActions'] ?? false ) ) {
			return self::validation_result(
				[],
				array_map(
					static fn( mixed $operation ): array => self::reject_operation(
						$operation,
						self::ERROR_STRUCTURAL_ACTIONS_DISABLED,
						'Block structural actions are disabled for this environment.'
					),
					$raw_operations
				),
				count( $raw_operations )
			);
		}

		if ( count( $raw_operations ) > 1 ) {
			return self::validation_result(
				[],
				array_map(
					static fn( mixed $operation ): array => self::reject_operation(
						$operation,
						self::ERROR_MULTI_OPERATION_UNSUPPORTED,
						'Only one block structural operation can be executable in this milestone.'
					),
					$raw_operations
				),
				count( $raw_operations )
			);
		}

		$result = self::validate_operation( $raw_operations[0], $context );

		if ( $result['ok'] ) {
			return self::validation_result( [ $result['operation'] ], [], 1 );
		}

		return self::validation_result( [], [ $result['rejection'] ], 1 );
	}

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @param array<int, array<string, mixed>> $rejected_operations
	 * @return array{ok: bool, catalogVersion: int, operations: array<int, array<string, mixed>>, rejectedOperations: array<int, array<string, mixed>>, proposedCount: int}
	 */
	private static function validation_result( array $operations, array $rejected_operations, int $proposed_count ): array {
		return [
			'ok'                 => ! empty( $operations ),
			'catalogVersion'     => self::CATALOG_VERSION,
			'operations'         => $operations,
			'rejectedOperations' => array_values( $rejected_operations ),
			'proposedCount'      => $proposed_count,
		];
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function validate_operation( mixed $raw_operation, array $context ): array {
		if ( ! is_array( $raw_operation ) ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_INVALID_OPERATION_PAYLOAD,
					'Block operations must be objects.'
				),
			];
		}

		$type = sanitize_key( (string) ( $raw_operation['type'] ?? '' ) );

		if ( ! in_array( $type, [ self::INSERT_PATTERN, self::REPLACE_BLOCK_WITH_PATTERN ], true ) ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_UNKNOWN_OPERATION_TYPE,
					'Unsupported block operation type.'
				),
			];
		}

		if ( self::normalize_target_surface( $raw_operation ) !== self::TARGET_BLOCK ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_CROSS_SURFACE_TARGET,
					'Block operations cannot target another recommendation surface.'
				),
			];
		}

		if ( self::normalize_target_type( $raw_operation ) !== self::TARGET_BLOCK ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_INVALID_TARGET_TYPE,
					'Block operations must target the selected block.'
				),
			];
		}

		$target_client_id = self::normalize_string( $raw_operation['targetClientId'] ?? '' );

		if ( '' === $target_client_id ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_MISSING_TARGET_CLIENT_ID,
					'Block operations must include targetClientId.'
				),
			];
		}

		if (
			'' === self::normalize_string( $context['targetClientId'] ?? '' )
			|| $target_client_id !== self::normalize_string( $context['targetClientId'] ?? '' )
		) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_STALE_TARGET,
					'Block operations must target the current selected block.'
				),
			];
		}

		$operation_signature = self::normalize_string( $raw_operation['targetSignature'] ?? '' );
		$context_signature   = self::normalize_string( $context['targetSignature'] ?? '' );

		if ( '' !== $operation_signature && '' !== $context_signature && $operation_signature !== $context_signature ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_STALE_TARGET,
					'Block operations must match the recommendation-time target signature.'
				),
			];
		}

		if ( true === ( $context['isTargetLocked'] ?? false ) ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_LOCKED_TARGET,
					'Block operations cannot mutate a locked target.'
				),
			];
		}

		if ( true === ( $context['isContentOnly'] ?? false ) || 'contentOnly' === ( $context['editingMode'] ?? '' ) ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_CONTENT_ONLY_TARGET,
					'Block operations cannot mutate a content-only target.'
				),
			];
		}

		$pattern_name = self::normalize_string( $raw_operation['patternName'] ?? '' );

		if ( '' === $pattern_name ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_MISSING_PATTERN_NAME,
					'Block operations must include patternName.'
				),
			];
		}

		$pattern = self::find_allowed_pattern( $context['allowedPatterns'] ?? [], $pattern_name );

		if ( null === $pattern ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_PATTERN_NOT_AVAILABLE,
					'Block operations must choose an allowed pattern.'
				),
			];
		}

		if ( self::INSERT_PATTERN === $type ) {
			return self::validate_insert_pattern_operation( $raw_operation, $context, $pattern, $target_client_id, $context_signature );
		}

		return self::validate_replace_block_with_pattern_operation( $raw_operation, $context, $pattern, $target_client_id, $context_signature );
	}

	/**
	 * @param array<string, mixed> $raw_operation
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $pattern
	 * @return array<string, mixed>
	 */
	private static function validate_insert_pattern_operation( array $raw_operation, array $context, array $pattern, string $target_client_id, string $context_signature ): array {
		$position = self::normalize_string( $raw_operation['position'] ?? '' );

		if ( ! in_array( $position, [ self::ACTION_INSERT_BEFORE, self::ACTION_INSERT_AFTER ], true ) ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_INVALID_INSERTION_POSITION,
					'Insert pattern operations must use insert_before or insert_after.'
				),
			];
		}

		if ( ! in_array( $position, $pattern['allowedActions'] ?? [], true ) ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_ACTION_NOT_ALLOWED,
					'The selected pattern does not allow that action for this target.'
				),
			];
		}

		return [
			'ok'        => true,
			'operation' => self::build_normalized_operation(
				self::INSERT_PATTERN,
				$pattern['name'],
				$target_client_id,
				$context,
				$context_signature,
				[ 'position' => $position ]
			),
		];
	}

	/**
	 * @param array<string, mixed> $raw_operation
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $pattern
	 * @return array<string, mixed>
	 */
	private static function validate_replace_block_with_pattern_operation( array $raw_operation, array $context, array $pattern, string $target_client_id, string $context_signature ): array {
		if ( ! in_array( self::ACTION_REPLACE, $pattern['allowedActions'] ?? [], true ) ) {
			return [
				'ok'        => false,
				'rejection' => self::reject_operation(
					$raw_operation,
					self::ERROR_ACTION_NOT_ALLOWED,
					'The selected pattern does not allow replacement for this target.'
				),
			];
		}

		return [
			'ok'        => true,
			'operation' => self::build_normalized_operation(
				self::REPLACE_BLOCK_WITH_PATTERN,
				$pattern['name'],
				$target_client_id,
				$context,
				$context_signature,
				[ 'action' => self::ACTION_REPLACE ]
			),
		];
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private static function build_normalized_operation( string $type, string $pattern_name, string $target_client_id, array $context, string $context_signature, array $extra = [] ): array {
		$operation = array_merge(
			[
				'catalogVersion' => self::CATALOG_VERSION,
				'type'           => $type,
				'patternName'    => $pattern_name,
				'targetClientId' => $target_client_id,
				'targetType'     => self::TARGET_BLOCK,
			],
			$extra
		);

		if ( '' !== $context_signature ) {
			$operation['targetSignature'] = $context_signature;
		}

		$target_block_name = self::normalize_string( $context['targetBlockName'] ?? '' );
		if ( '' !== $target_block_name ) {
			$operation['expectedTarget'] = [
				'clientId' => $target_client_id,
				'name'     => $target_block_name,
			];
		}

		return $operation;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function reject_operation( mixed $raw_operation, string $code, string $message ): array {
		return [
			'code'      => $code,
			'message'   => $message,
			'operation' => self::sanitize_operation_payload( $raw_operation ),
		];
	}

	private static function sanitize_operation_payload( mixed $raw_operation ): ?array {
		if ( ! is_array( $raw_operation ) ) {
			return null;
		}

		$payload = [];

		foreach ( [ 'type', 'patternName', 'targetClientId', 'position', 'targetSignature', 'targetSurface', 'targetType', 'surface' ] as $field ) {
			if ( array_key_exists( $field, $raw_operation ) ) {
				$payload[ $field ] = self::normalize_string( $raw_operation[ $field ] );
			}
		}

		return $payload;
	}

	private static function normalize_target_surface( array $raw_operation ): string {
		$surface = self::normalize_string( $raw_operation['targetSurface'] ?? $raw_operation['surface'] ?? '' );

		return '' !== $surface ? $surface : self::TARGET_BLOCK;
	}

	private static function normalize_target_type( array $raw_operation ): string {
		$target_type = self::normalize_string( $raw_operation['targetType'] ?? '' );

		return '' !== $target_type ? $target_type : self::TARGET_BLOCK;
	}

	/**
	 * @param mixed $allowed_patterns
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_allowed_patterns( mixed $allowed_patterns ): array {
		if ( ! is_array( $allowed_patterns ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $allowed_patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$name = self::normalize_string( $pattern['name'] ?? '' );

			if ( '' === $name ) {
				continue;
			}

			$actions = array_values(
				array_intersect(
					self::normalize_string_list( $pattern['allowedActions'] ?? [] ),
					self::ALLOWED_ACTIONS
				)
			);

			if ( empty( $actions ) ) {
				continue;
			}

			$normalized[] = [
				'name'           => $name,
				'title'          => self::normalize_string( $pattern['title'] ?? '' ),
				'source'         => sanitize_key( (string) ( $pattern['source'] ?? '' ) ),
				'categories'     => self::normalize_string_list( $pattern['categories'] ?? [] ),
				'blockTypes'     => self::normalize_string_list( $pattern['blockTypes'] ?? [] ),
				'allowedActions' => $actions,
			];
		}

		return $normalized;
	}

	private static function find_allowed_pattern( mixed $allowed_patterns, string $pattern_name ): ?array {
		foreach ( self::normalize_allowed_patterns( $allowed_patterns ) as $pattern ) {
			if ( $pattern['name'] === $pattern_name ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private static function normalize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $entry ): string => self::normalize_string( $entry ),
						$value
					),
					static fn( string $entry ): bool => '' !== $entry
				)
			)
		);
	}

	private static function normalize_string( mixed $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}
}
