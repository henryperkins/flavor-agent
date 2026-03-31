<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class Serializer {

	private const UNDO_STATUS_AVAILABLE = 'available';
	private const UNDO_STATUS_FAILED    = 'failed';
	private const UNDO_STATUS_UNDONE    = 'undone';

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public static function normalize_entry( array $entry ): array {
		$timestamp = self::normalize_timestamp( $entry['timestamp'] ?? null );

		return [
			'id'              => self::normalize_string( $entry['id'] ?? '' ),
			'schemaVersion'   => self::normalize_positive_int( $entry['schemaVersion'] ?? 1, 1 ),
			'type'            => self::normalize_string( $entry['type'] ?? '' ),
			'surface'         => self::normalize_string( $entry['surface'] ?? '' ),
			'target'          => self::normalize_structured_value( $entry['target'] ?? [] ),
			'suggestion'      => self::normalize_string( $entry['suggestion'] ?? '' ),
			'suggestionKey'   => self::normalize_nullable_string( $entry['suggestionKey'] ?? null ),
			'before'          => self::normalize_structured_value( $entry['before'] ?? [] ),
			'after'           => self::normalize_structured_value( $entry['after'] ?? [] ),
			'request'         => self::normalize_structured_value( $entry['request'] ?? [] ),
			'document'        => self::normalize_structured_value( $entry['document'] ?? [] ),
			'timestamp'       => $timestamp,
			'executionResult' => self::normalize_string( $entry['executionResult'] ?? 'applied' ),
			'undo'            => self::normalize_undo_for_storage(
				is_array( $entry['undo'] ?? null ) ? $entry['undo'] : [],
				$timestamp
			),
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{type: string, ref: string}
	 */
	public static function derive_entity( array $entry ): array {
		$surface      = (string) ( $entry['surface'] ?? '' );
		$target       = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$document     = is_array( $entry['document'] ?? null ) ? $entry['document'] : [];
		$document_key = self::normalize_string( $document['scopeKey'] ?? '' );

		if ( 'template' === $surface ) {
			$template_ref = self::normalize_string( $target['templateRef'] ?? '' );

			return [
				'type' => 'template',
				'ref'  => '' !== $template_ref ? $template_ref : $document_key,
			];
		}

		if ( 'template-part' === $surface ) {
			$template_part_ref = self::normalize_string( $target['templatePartRef'] ?? '' );

			return [
				'type' => 'template-part',
				'ref'  => '' !== $template_part_ref ? $template_part_ref : $document_key,
			];
		}

		if ( 'global-styles' === $surface ) {
			$global_styles_ref = self::normalize_string( $target['globalStylesId'] ?? '' );

			return [
				'type' => 'global-styles',
				'ref'  => '' !== $global_styles_ref ? $global_styles_ref : $document_key,
			];
		}

		$block_name = self::normalize_string( $target['blockName'] ?? '' );
		$block_path = '';

		if ( is_array( $target['blockPath'] ?? null ) && [] !== $target['blockPath'] ) {
			$block_path = implode(
				'.',
				array_map(
					static fn ( $index ): string => (string) (int) $index,
					$target['blockPath']
				)
			);
		}

		$client_id = self::normalize_string( $target['clientId'] ?? '' );
		$ref       = $document_key;

		if ( '' !== $block_path ) {
			$ref .= ':path:' . $block_path;
		} elseif ( '' !== $client_id ) {
			$ref .= ':client:' . $client_id;
		}

		if ( '' !== $block_name ) {
			$ref .= ':block:' . $block_name;
		}

		return [
			'type' => 'block',
			'ref'  => $ref,
		];
	}

	public static function encode_json( $value ): string {
		return (string) wp_json_encode( self::normalize_structured_value( $value ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function decode_json( ?string $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<string, mixed> $undo
	 * @return array<string, mixed>
	 */
	public static function normalize_undo_for_storage( array $undo, string $timestamp ): array {
		$status = self::normalize_string( $undo['status'] ?? self::UNDO_STATUS_AVAILABLE );

		if ( ! in_array( $status, [ self::UNDO_STATUS_AVAILABLE, self::UNDO_STATUS_FAILED, self::UNDO_STATUS_UNDONE ], true ) ) {
			$status = self::UNDO_STATUS_AVAILABLE;
		}

		$normalized = [
			'canUndo'   => self::UNDO_STATUS_AVAILABLE === $status,
			'status'    => $status,
			'error'     => self::UNDO_STATUS_FAILED === $status
				? self::normalize_nullable_string( $undo['error'] ?? null )
				: null,
			'updatedAt' => self::normalize_timestamp( $undo['updatedAt'] ?? $timestamp ),
			'undoneAt'  => self::UNDO_STATUS_UNDONE === $status
				? self::normalize_timestamp( $undo['undoneAt'] ?? $timestamp )
				: null,
		];

		return $normalized;
	}

	public static function build_default_undo( string $timestamp ): array {
		return self::normalize_undo_for_storage( [], $timestamp );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate_row( array $row ): array {
		$timestamp = self::mysql_to_timestamp( (string) ( $row['created_at'] ?? '' ) );
		$undo      = self::normalize_undo_for_storage(
			self::decode_json( isset( $row['undo_state'] ) ? (string) $row['undo_state'] : '' ),
			$timestamp
		);
		$user_id   = self::normalize_positive_int( $row['user_id'] ?? 0, 0 );

		return [
			'id'              => self::normalize_string( $row['activity_id'] ?? '' ),
			'schemaVersion'   => self::normalize_positive_int( $row['schema_version'] ?? 1, 1 ),
			'type'            => self::normalize_string( $row['activity_type'] ?? '' ),
			'surface'         => self::normalize_string( $row['surface'] ?? '' ),
			'target'          => self::decode_json( isset( $row['target_json'] ) ? (string) $row['target_json'] : '' ),
			'suggestion'      => self::normalize_string( $row['suggestion'] ?? '' ),
			'suggestionKey'   => self::normalize_nullable_string( $row['suggestion_key'] ?? null ),
			'before'          => self::decode_json( isset( $row['before_state'] ) ? (string) $row['before_state'] : '' ),
			'after'           => self::decode_json( isset( $row['after_state'] ) ? (string) $row['after_state'] : '' ),
			'request'         => self::decode_json( isset( $row['request_json'] ) ? (string) $row['request_json'] : '' ),
			'document'        => self::decode_json( isset( $row['document_json'] ) ? (string) $row['document_json'] : '' ),
			'timestamp'       => $timestamp,
			'executionResult' => self::normalize_string( $row['execution_result'] ?? 'applied' ),
			'undo'            => $undo,
			'userId'          => $user_id,
			'userLabel'       => self::resolve_user_label( $user_id ),
			'persistence'     => [
				'status' => 'server',
			],
		];
	}

	public static function normalize_timestamp( $value ): string {
		if ( is_string( $value ) && '' !== $value ) {
			$timestamp = strtotime( $value );

			if ( false !== $timestamp ) {
				return gmdate( 'c', $timestamp );
			}
		}

		return gmdate( 'c' );
	}

	public static function mysql_datetime_from_timestamp( string $timestamp ): string {
		$unix_timestamp = strtotime( $timestamp );

		if ( false === $unix_timestamp ) {
			$unix_timestamp = time();
		}

		return gmdate( 'Y-m-d H:i:s', $unix_timestamp );
	}

	private static function mysql_to_timestamp( string $value ): string {
		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return gmdate( 'c' );
		}

		return gmdate( 'c', $timestamp );
	}

	public static function normalize_structured_value( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $entry ) {
				$normalized[ $key ] = self::normalize_structured_value( $entry );
			}

			return $normalized;
		}

		if (
			is_string( $value )
			|| is_int( $value )
			|| is_float( $value )
			|| is_bool( $value )
			|| null === $value
		) {
			return $value;
		}

		return null;
	}

	private static function normalize_nullable_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = trim( (string) $value );

		return '' === $string ? null : $string;
	}

	private static function normalize_positive_int( $value, int $fallback ): int {
		$normalized = (int) $value;

		return $normalized >= 0 ? $normalized : $fallback;
	}

	private static function normalize_string( $value ): string {
		return trim( (string) $value );
	}

	private static function resolve_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'get_userdata' ) ) {
			$user = \get_userdata( $user_id );

			if ( is_object( $user ) && isset( $user->display_name ) ) {
				$display_name = trim( (string) $user->display_name );

				if ( '' !== $display_name ) {
					return $display_name;
				}
			}
		}

		return sprintf( 'User #%d', $user_id );
	}
}
