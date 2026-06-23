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
			'operations'              => array_values( (array) ( $params['operations'] ?? [] ) ),
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
