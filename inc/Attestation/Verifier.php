<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Pure evaluation of public attestation verification outcomes. No I/O.
 */
final class Verifier {

	private const MAX_CHAIN_RECORDS = 50;

	/**
	 * Strictly verify one public envelope and, when necessary, resolve a signed
	 * revert/supersession chain to the current live subject.
	 *
	 * @param array<string, mixed>                            $envelope
	 * @param array{keys?: array<int, array<string, mixed>>} $jwks
	 * @param callable(string): ?array<string, mixed>|null   $resolve_envelope
	 * @return array<string, mixed>
	 */
	public static function verify(
		array $envelope,
		array $jwks,
		?string $live_subject_bytes,
		string $expected_attestation_id,
		string $expected_site_url,
		?callable $resolve_envelope = null
	): array {
		$validated = StatementValidator::validate(
			$envelope,
			$jwks,
			$expected_attestation_id,
			$expected_site_url
		);
		$outcomes  = $validated['outcomes'];
		$claims    = is_array( $validated['claims'] ) ? $validated['claims'] : null;

		if ( ! $validated['valid'] || null === $claims ) {
			return self::result( $expected_attestation_id, $outcomes, 'invalid', null, 0, 1 );
		}

		if ( null === $live_subject_bytes ) {
			$outcomes[] = 'live_subject_unavailable';

			return self::result( $expected_attestation_id, $outcomes, 'incomplete', null, 0, 3 );
		}

		$live_digest = hash( 'sha256', $live_subject_bytes );

		if ( $live_digest === $claims['afterDigest'] ) {
			$outcomes[] = 'live_matches_subject';

			return self::result( $expected_attestation_id, $outcomes, 'verified', $expected_attestation_id, 0, 0 );
		}

		$candidates = self::chain_candidates( $envelope, $claims, 1, null );

		if ( [] === $candidates || null === $resolve_envelope ) {
			$outcomes[] = 'live_changed_since_attestation';

			return self::result( $expected_attestation_id, $outcomes, 'warning', null, 0, 0 );
		}

		$visited    = [ $expected_attestation_id => true ];
		$seen_count = 1;
		$invalid    = false;
		$incomplete = false;

		while ( [] !== $candidates ) {
			$candidate = array_shift( $candidates );
			$id        = (string) $candidate['id'];

			if ( isset( $visited[ $id ] ) ) {
				$invalid = true;
				continue;
			}

			if ( $seen_count >= self::MAX_CHAIN_RECORDS ) {
				$incomplete = true;
				break;
			}

			$visited[ $id ] = true;
			++$seen_count;
			$child_envelope = $resolve_envelope( $id );

			if ( ! is_array( $child_envelope ) || true === ( $child_envelope['_resolution_incomplete'] ?? false ) ) {
				$incomplete = true;
				continue;
			}

			$child_validated = StatementValidator::validate( $child_envelope, $jwks, $id, $expected_site_url );
			$child_claims    = is_array( $child_validated['claims'] ) ? $child_validated['claims'] : null;

			if (
				! $child_validated['valid']
				|| null === $child_claims
				|| ! self::valid_chain_edge( $candidate, $child_claims )
			) {
				$invalid = true;
				continue;
			}

			if ( $live_digest === $child_claims['afterDigest'] ) {
				$outcomes[] = 'revert' === $candidate['firstType']
					? 'reverted_by_attestation'
					: 'superseded_by_attestation';

				return self::result(
					$expected_attestation_id,
					$outcomes,
					'verified',
					$id,
					(int) $candidate['depth'],
					0
				);
			}

			$candidates = array_merge(
				$candidates,
				self::chain_candidates(
					$child_envelope,
					$child_claims,
					(int) $candidate['depth'] + 1,
					(string) $candidate['firstType']
				)
			);
		}

		$outcomes[] = 'live_changed_since_attestation';

		if ( $invalid ) {
			$outcomes[] = 'chain_invalid';

			return self::result( $expected_attestation_id, $outcomes, 'invalid', null, 0, 1 );
		}

		if ( $incomplete ) {
			$outcomes[] = 'chain_resolution_incomplete';

			return self::result( $expected_attestation_id, $outcomes, 'incomplete', null, 0, 3 );
		}

		return self::result( $expected_attestation_id, $outcomes, 'warning', null, 0, 0 );
	}

	/**
	 * @param array<string, mixed> $envelope
	 * @param array<string, mixed> $claims
	 * @return list<array<string, mixed>>
	 */
	private static function chain_candidates( array $envelope, array $claims, int $depth, ?string $first_type ): array {
		$candidates = [];

		foreach (
			[
				'revert'    => 'reverted_by_attestation_id',
				'supersede' => 'superseded_by_attestation_id',
			] as $type => $field
		) {
			$id = isset( $envelope[ $field ] ) ? trim( (string) $envelope[ $field ] ) : '';

			if ( '' === $id ) {
				continue;
			}

			$candidates[] = [
				'id'           => $id,
				'type'         => $type,
				'firstType'    => null === $first_type ? $type : $first_type,
				'depth'        => $depth,
				'parentClaims' => $claims,
			];
		}

		return $candidates;
	}

	/**
	 * @param array<string, mixed> $edge
	 * @param array<string, mixed> $child
	 */
	private static function valid_chain_edge( array $edge, array $child ): bool {
		$parent = is_array( $edge['parentClaims'] ?? null ) ? $edge['parentClaims'] : [];
		$type   = (string) ( $edge['type'] ?? '' );
		$link   = 'revert' === $type
			? ( $child['revertsAttestationId'] ?? null )
			: ( $child['supersedesAttestationId'] ?? null );

		return ( $parent['attestationId'] ?? null ) === $link
			&& ( $parent['subjectName'] ?? null ) === ( $child['subjectName'] ?? null )
			&& ( $parent['subjectScope'] ?? null ) === ( $child['subjectScope'] ?? null )
			&& ( $parent['afterDigest'] ?? null ) === ( $child['beforeDigest'] ?? null )
			&& ( 'revert' === $type ? 'revert' : 'approve' ) === ( $child['decision'] ?? null );
	}

	/**
	 * @param list<string> $outcomes
	 * @return array<string, mixed>
	 */
	private static function result(
		string $attestation_id,
		array $outcomes,
		string $status,
		?string $terminal_id,
		int $chain_depth,
		int $exit_code
	): array {
		return [
			'attestationId'         => $attestation_id,
			'outcomes'              => array_values( array_unique( $outcomes ) ),
			'verificationStatus'    => $status,
			'terminalAttestationId' => $terminal_id,
			'chainDepth'            => $chain_depth,
			'error'                 => null,
			'exitCode'              => $exit_code,
		];
	}

	private function __construct() {}
}
