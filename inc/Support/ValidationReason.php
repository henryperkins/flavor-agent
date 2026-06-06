<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class ValidationReason {

	public const VERSION = 'validation-reasons-v1';

	public const SEVERITY_REJECTED   = 'rejected';
	public const SEVERITY_DOWNGRADED = 'downgraded';
	public const SEVERITY_NO_OP      = 'no_op';

	private const SEVERITY_RANK = [
		self::SEVERITY_REJECTED   => 2,
		self::SEVERITY_DOWNGRADED => 1,
		self::SEVERITY_NO_OP      => 0,
	];

	private const MAX_MESSAGE_LENGTH = 191;

	/**
	 * Embedded copy of shared/validation-reasons.json. Edit
	 * shared/validation-reasons.json AND this const together (a parity test
	 * enforces equality). This const encodes code => severity only; the JSON's
	 * `surfaces` arrays are JSON-only metadata and are intentionally not
	 * mirrored here.
	 *
	 * @var array<string, string> code => default severity
	 */
	private const VOCABULARY = [
		'block_structural_actions_disabled' => self::SEVERITY_REJECTED,
		'multi_operation_unsupported'       => self::SEVERITY_REJECTED,
		'invalid_operation_payload'         => self::SEVERITY_REJECTED,
		'unknown_operation_type'            => self::SEVERITY_REJECTED,
		'missing_pattern_name'              => self::SEVERITY_REJECTED,
		'pattern_not_available'             => self::SEVERITY_REJECTED,
		'missing_target_client_id'          => self::SEVERITY_REJECTED,
		'stale_target'                      => self::SEVERITY_REJECTED,
		'cross_surface_target'              => self::SEVERITY_REJECTED,
		'invalid_target_type'               => self::SEVERITY_REJECTED,
		'locked_target'                     => self::SEVERITY_REJECTED,
		'content_only_target'               => self::SEVERITY_REJECTED,
		'invalid_insertion_position'        => self::SEVERITY_REJECTED,
		'action_not_allowed'                => self::SEVERITY_REJECTED,
		'client_server_operation_mismatch'  => self::SEVERITY_REJECTED,
		'unsupported_scope'                 => self::SEVERITY_REJECTED,
		'unsupported_path'                  => self::SEVERITY_REJECTED,
		'failed_contrast'                   => self::SEVERITY_DOWNGRADED,
		'preset_required'                   => self::SEVERITY_REJECTED,
		'preset_metadata_mismatch'          => self::SEVERITY_REJECTED,
		'preset_reference_mismatch'         => self::SEVERITY_REJECTED,
		'preset_unavailable'                => self::SEVERITY_REJECTED,
		'invalid_freeform_value'            => self::SEVERITY_REJECTED,
		'raw_value_when_preset_available'   => self::SEVERITY_DOWNGRADED,
		'duplicate_or_noop'                 => self::SEVERITY_NO_OP,
		'responsive_visibility_risk'        => self::SEVERITY_DOWNGRADED,
		'excessive_visual_complexity'       => self::SEVERITY_DOWNGRADED,
		'missing_style_book_target'         => self::SEVERITY_REJECTED,
		'unavailable_variation'             => self::SEVERITY_REJECTED,
		'no_executable_operations'          => self::SEVERITY_REJECTED,
		'invalid_template_area'             => self::SEVERITY_REJECTED,
		'no_assigned_part'                  => self::SEVERITY_REJECTED,
		'duplicate_area_mutation'           => self::SEVERITY_REJECTED,
		'area_mismatch'                     => self::SEVERITY_REJECTED,
		'same_slug_no_op'                   => self::SEVERITY_NO_OP,
		'invalid_anchor'                    => self::SEVERITY_REJECTED,
		'invalid_placement'                 => self::SEVERITY_REJECTED,
		'unknown_pattern'                   => self::SEVERITY_REJECTED,
		'repeated_pattern_insert'           => self::SEVERITY_REJECTED,
		'malformed_operation'               => self::SEVERITY_REJECTED,
		'overlapping_block_paths'           => self::SEVERITY_REJECTED,
		'too_many_operations'               => self::SEVERITY_REJECTED,
		'advisory_only'                     => self::SEVERITY_DOWNGRADED,
		'missing_structural_context'        => self::SEVERITY_REJECTED,
		'operation_validation_failed'       => self::SEVERITY_REJECTED,
		'no_op'                             => self::SEVERITY_NO_OP,
	];

	/**
	 * @return array<string, string>
	 */
	public static function vocabulary(): array {
		return self::VOCABULARY;
	}

	private static function normalize_code( mixed $value ): string {
		$code = strtolower( (string) $value );
		$code = (string) preg_replace( '/[^a-z0-9_-]+/', '_', $code );

		return substr( trim( $code, '_' ), 0, 64 );
	}

	private static function normalize_severity( string $code, mixed $explicit ): string {
		$explicit = is_string( $explicit ) ? $explicit : '';

		if ( isset( self::SEVERITY_RANK[ $explicit ] ) ) {
			return $explicit;
		}

		return self::VOCABULARY[ $code ] ?? self::SEVERITY_REJECTED;
	}

	/**
	 * @param array<int, mixed> $raw
	 * @return array<int, array{code: string, severity: string, message?: string}>
	 */
	public static function normalize( array $raw ): array {
		$out = [];

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$code = self::normalize_code( $entry['code'] ?? '' );

			if ( '' === $code ) {
				continue;
			}

			$reason = [
				'code'     => $code,
				'severity' => self::normalize_severity( $code, $entry['severity'] ?? null ),
			];

			$message = isset( $entry['message'] ) ? sanitize_text_field( (string) $entry['message'] ) : '';
			if ( '' !== $message ) {
				$reason['message'] = self::truncate( $message, self::MAX_MESSAGE_LENGTH );
			}

			$out[] = $reason;
		}

		return $out;
	}

	/**
	 * Multibyte-safe truncation so persisted/audited messages never carry a
	 * split UTF-8 character. Mirrors GuidanceExcerpt::string_substr.
	 */
	private static function truncate( string $value, int $length ): string {
		return function_exists( 'mb_substr' )
			? (string) mb_substr( $value, 0, $length, 'UTF-8' )
			: substr( $value, 0, $length );
	}

	/**
	 * @param array<int, array<string, mixed>> $reasons
	 * @return array{code: string, severity: string}|array{}
	 */
	public static function primary( array $reasons ): array {
		$normalized = self::normalize( $reasons );

		if ( [] === $normalized ) {
			return [];
		}

		usort(
			$normalized,
			static fn( array $a, array $b ): int =>
				( self::SEVERITY_RANK[ $b['severity'] ] ?? 0 ) <=> ( self::SEVERITY_RANK[ $a['severity'] ] ?? 0 )
		);

		return [
			'code'     => $normalized[0]['code'],
			'severity' => $normalized[0]['severity'],
		];
	}
}
