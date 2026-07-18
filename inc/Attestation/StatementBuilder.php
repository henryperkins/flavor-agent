<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

final class StatementBuilder {

	public const PREDICATE_TYPE = 'https://flavor-agent.dev/attestation/governed-change/v1';

	/**
	 * @param array<string, mixed> $params
	 */
	public static function build( array $params ): string {
		$statement = [
			'_type'         => 'https://in-toto.io/Statement/v1',
			'subject'       => [
				[
					'name'   => (string) $params['subjectName'],
					'scope'  => (string) $params['scope'],
					'digest' => [ 'sha256' => (string) $params['afterDigest'] ],
				],
			],
			'predicateType' => self::PREDICATE_TYPE,
			'predicate'     => self::public_safe_predicate( $params ),
		];

		return self::canonical_json( $statement );
	}

	/**
	 * ALLOWLIST: only these keys are ever emitted.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private static function public_safe_predicate( array $params ): array {
		return [
			'attestationId'           => (string) $params['attestationId'],
			'schemaVersion'           => 1,
			'surface'                 => (string) $params['surface'],
			'governance'              => [
				'claim'           => (string) ( $params['governanceClaim'] ?? '' ),
				'lane'            => (string) ( $params['governanceLane'] ?? '' ),
				'approvalSurface' => (string) ( $params['approvalSurface'] ?? '' ),
				'executor'        => (string) ( $params['executor'] ?? '' ),
			],
			'operations'              => self::public_safe_operations(
				$params['operations'] ?? [],
				(string) ( $params['governanceLane'] ?? '' )
			),
			'before'                  => [ 'sha256' => (string) $params['beforeDigest'] ],
			'after'                   => [ 'sha256' => (string) $params['afterDigest'] ],
			'freshnessSignature'      => (string) ( $params['freshnessSignature'] ?? '' ),
			'actor'                   => [
				'role'        => (string) ( $params['actorRole'] ?? '' ),
				'proposerVia' => (string) ( $params['proposerVia'] ?? '' ),
			],
			'decision'                => (string) ( $params['decision'] ?? 'approve' ),
			'timestamps'              => [
				'requestedAt' => (string) ( $params['requestedAt'] ?? '' ),
				'decidedAt'   => (string) ( $params['decidedAt'] ?? '' ),
			],
			'site'                    => [
				'url'   => (string) ( $params['siteUrl'] ?? '' ),
				'keyId' => (string) ( $params['keyId'] ?? '' ),
			],
			'revertsAttestationId'    => isset( $params['revertsAttestationId'] ) ? (string) $params['revertsAttestationId'] : null,
			'supersedesAttestationId' => isset( $params['supersedesAttestationId'] ) ? (string) $params['supersedesAttestationId'] : null,
			'relatedActivityId'       => isset( $params['relatedActivityId'] ) ? (string) $params['relatedActivityId'] : null,
		];
	}

	/**
	 * Project validated executor operations onto the exact public provenance
	 * contract. Unknown lane fields fail closed; private target attributes and
	 * editor labels are recognized but deliberately omitted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function public_safe_operations( mixed $operations, string $lane ): array {
		if ( ! is_array( $operations ) || ! array_is_list( $operations ) ) {
			throw new \InvalidArgumentException( 'Attestation operations must be a list.' );
		}

		$public = [];

		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) || array_is_list( $operation ) ) {
				throw new \InvalidArgumentException( 'Each attestation operation must be an object.' );
			}

			$public[] = match ( $lane ) {
				'external-style-apply-v1'         => self::public_style_operation( $operation ),
				'external-template-apply-v1'      => self::public_structural_operation( $operation, [ 'insert_pattern' ] ),
				'external-template-part-apply-v1' => self::public_structural_operation(
					$operation,
					[ 'insert_pattern', 'replace_block_with_pattern', 'remove_block' ]
				),
				default => throw new \InvalidArgumentException( 'Attestation operations require a recognized governance lane.' ),
			};
		}

		return $public;
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function public_style_operation( array $operation ): array {
		$type = self::required_string( $operation, 'type' );

		if ( 'set_theme_variation' === $type ) {
			self::assert_exact_keys( $operation, [ 'type', 'variationIndex', 'variationTitle' ] );

			if ( ! is_int( $operation['variationIndex'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Attestation variationIndex must be an integer.' );
			}

			return [
				'type'           => $type,
				'variationIndex' => $operation['variationIndex'],
				'variationTitle' => self::required_string( $operation, 'variationTitle' ),
			];
		}

		if ( ! in_array( $type, [ 'set_styles', 'set_block_styles' ], true ) ) {
			throw new \InvalidArgumentException( 'Unsupported public style attestation operation.' );
		}

		self::assert_exact_keys(
			$operation,
			[ 'type', 'blockName', 'path', 'value', 'valueType', 'presetType', 'presetSlug', 'cssVar', 'beforeValue' ]
		);

		$value = $operation['value'] ?? null;

		if ( ! is_scalar( $value ) && null !== $value ) {
			throw new \InvalidArgumentException( 'Attestation style values must be scalar or null.' );
		}

		return [
			'type'       => $type,
			'blockName'  => self::required_string( $operation, 'blockName' ),
			'path'       => self::string_path( $operation['path'] ?? null ),
			'value'      => $value,
			'valueType'  => self::required_string( $operation, 'valueType' ),
			'presetType' => self::required_string( $operation, 'presetType' ),
			'presetSlug' => self::required_string( $operation, 'presetSlug' ),
			'cssVar'     => self::required_string( $operation, 'cssVar' ),
		];
	}

	/**
	 * @param array<string, mixed> $operation
	 * @param string[]             $allowed_types
	 * @return array<string, mixed>
	 */
	private static function public_structural_operation( array $operation, array $allowed_types ): array {
		$type = self::required_string( $operation, 'type' );

		if ( ! in_array( $type, $allowed_types, true ) ) {
			throw new \InvalidArgumentException( 'Operation type is not allowed in this attestation lane.' );
		}

		if ( 'insert_pattern' === $type ) {
			self::assert_exact_keys(
				$operation,
				[ 'type', 'patternName', 'placement', 'targetPath', 'expectedTarget' ]
			);

			$public = [
				'type'        => $type,
				'patternName' => self::required_string( $operation, 'patternName' ),
				'placement'   => self::required_string( $operation, 'placement' ),
			];

			$has_path   = array_key_exists( 'targetPath', $operation );
			$has_target = array_key_exists( 'expectedTarget', $operation );

			if ( $has_path !== $has_target ) {
				throw new \InvalidArgumentException( 'Path-addressed attestation operations require an expected target.' );
			}

			if ( $has_path ) {
				$public['targetPath']     = self::integer_path( $operation['targetPath'] );
				$public['expectedTarget'] = self::public_expected_target( $operation['expectedTarget'] );
			}

			return $public;
		}

		$allowed_keys = [ 'type', 'expectedBlockName', 'expectedTarget', 'targetPath' ];

		if ( 'replace_block_with_pattern' === $type ) {
			$allowed_keys[] = 'patternName';
		}

		self::assert_exact_keys( $operation, $allowed_keys );

		$public = [
			'type'              => $type,
			'expectedBlockName' => self::required_string( $operation, 'expectedBlockName' ),
			'expectedTarget'    => self::public_expected_target( $operation['expectedTarget'] ?? null ),
			'targetPath'        => self::integer_path( $operation['targetPath'] ?? null ),
		];

		if ( 'replace_block_with_pattern' === $type ) {
			$public['patternName'] = self::required_string( $operation, 'patternName' );
		}

		return $public;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function public_expected_target( mixed $target ): array {
		if ( ! is_array( $target ) || array_is_list( $target ) ) {
			throw new \InvalidArgumentException( 'Attestation expectedTarget must be an object.' );
		}

		self::assert_exact_keys(
			$target,
			[ 'name', 'label', 'attributes', 'childCount', 'slot' ]
		);

		if ( isset( $target['label'] ) && ! is_string( $target['label'] ) ) {
			throw new \InvalidArgumentException( 'Attestation expectedTarget label must be a string.' );
		}

		if ( isset( $target['attributes'] ) && ! is_array( $target['attributes'] ) ) {
			throw new \InvalidArgumentException( 'Attestation expectedTarget attributes must be an object.' );
		}

		if ( ! is_int( $target['childCount'] ?? null ) || $target['childCount'] < 0 ) {
			throw new \InvalidArgumentException( 'Attestation expectedTarget childCount must be a non-negative integer.' );
		}

		$public = [
			'name'       => self::required_string( $target, 'name' ),
			'childCount' => $target['childCount'],
		];

		if ( array_key_exists( 'slot', $target ) ) {
			$slot = $target['slot'];

			if ( ! is_array( $slot ) || array_is_list( $slot ) ) {
				throw new \InvalidArgumentException( 'Attestation expectedTarget slot must be an object.' );
			}

			self::assert_exact_keys( $slot, [ 'slug', 'area', 'isEmpty' ] );

			if ( ! is_bool( $slot['isEmpty'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Attestation expectedTarget slot isEmpty must be boolean.' );
			}

			$public['slot'] = [
				'slug'    => self::required_string( $slot, 'slug' ),
				'area'    => self::required_string( $slot, 'area' ),
				'isEmpty' => $slot['isEmpty'],
			];
		}

		return $public;
	}

	/**
	 * @param array<string, mixed> $value
	 * @param string[]             $allowed_keys
	 */
	private static function assert_exact_keys( array $value, array $allowed_keys ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed_keys, true ) ) {
				throw new \InvalidArgumentException( 'Non-allowlisted attestation field.' );
			}
		}
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private static function required_string( array $value, string $key ): string {
		if ( ! array_key_exists( $key, $value ) || ! is_string( $value[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Attestation field must be a string.' );
		}

		return $value[ $key ];
	}

	/**
	 * @return string[]
	 */
	private static function string_path( mixed $path ): array {
		if ( ! is_array( $path ) || ! array_is_list( $path ) || [] === $path ) {
			throw new \InvalidArgumentException( 'Attestation path must be a non-empty list.' );
		}

		foreach ( $path as $segment ) {
			if ( ! is_string( $segment ) || '' === $segment ) {
				throw new \InvalidArgumentException( 'Attestation path segments must be non-empty strings.' );
			}
		}

		return $path;
	}

	/**
	 * @return int[]
	 */
	private static function integer_path( mixed $path ): array {
		if ( ! is_array( $path ) || ! array_is_list( $path ) || [] === $path ) {
			throw new \InvalidArgumentException( 'Attestation path must be a non-empty list.' );
		}

		foreach ( $path as $segment ) {
			if ( ! is_int( $segment ) || $segment < 0 ) {
				throw new \InvalidArgumentException( 'Attestation path segments must be non-negative integers.' );
			}
		}

		return $path;
	}

	/**
	 * Deterministic JSON: recursively sort associative arrays; preserve list order.
	 *
	 * @param array<string|int, mixed> $data
	 */
	public static function canonical_json( array $data ): string {
		self::ksort_deep( $data );

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			throw new \RuntimeException( 'Failed to encode canonical attestation JSON.' );
		}

		return $json;
	}

	/**
	 * @param array<string|int, mixed> $data
	 */
	private static function ksort_deep( array &$data ): void {
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_deep( $value );
			}
		}
		unset( $value );

		if ( ! array_is_list( $data ) ) {
			ksort( $data );
		}
	}

	private function __construct() {}
}
