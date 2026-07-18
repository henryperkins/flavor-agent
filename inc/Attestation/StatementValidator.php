<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Validates a signed attestation envelope against Flavor Agent's v1 profile
 * and the site/id context in which the envelope was requested.
 */
final class StatementValidator {

	private const STATEMENT_TYPE   = 'https://in-toto.io/Statement/v1';
	private const GOVERNANCE_CLAIM = 'governed-change';
	private const APPROVAL_SURFACE = 'settings-ai-activity';

	/**
	 * @param array<string, mixed>                               $envelope
	 * @param array{keys?: array<int, array<string, mixed>>}    $jwks
	 * @return array{valid: bool, outcomes: list<string>, claims: array<string, mixed>|null}
	 */
	public static function validate(
		array $envelope,
		array $jwks,
		string $expected_attestation_id,
		string $expected_site_url
	): array {
		$statement_b64   = is_string( $envelope['statement_b64'] ?? null ) ? $envelope['statement_b64'] : '';
		$signature_b64   = is_string( $envelope['signature_b64'] ?? null ) ? $envelope['signature_b64'] : '';
		$statement_bytes = self::b64url_decode( $statement_b64 );
		$signature_raw   = self::b64url_decode( $signature_b64 );
		$statement       = json_decode( $statement_bytes, true );
		$statement       = is_array( $statement ) ? $statement : [];
		$predicate       = is_array( $statement['predicate'] ?? null ) ? $statement['predicate'] : [];
		$site            = is_array( $predicate['site'] ?? null ) ? $predicate['site'] : [];
		$key_id          = is_string( $site['keyId'] ?? null ) ? $site['keyId'] : '';
		$envelope_key_id = is_string( $envelope['key_id'] ?? null ) ? $envelope['key_id'] : '';
		$lookup_key_id   = '' !== $envelope_key_id
			? $envelope_key_id
			: $key_id;
		$public_key      = self::public_key_for( $jwks, $lookup_key_id );
		$signature_valid = null !== $public_key
			&& Signer::verify( $statement_bytes, $signature_raw, $public_key );
		$outcomes        = [ $signature_valid ? 'signature_valid' : 'record_tampered' ];

		if ( ! $signature_valid ) {
			return [
				'valid'    => false,
				'outcomes' => $outcomes,
				'claims'   => null,
			];
		}

		$subject_list = $statement['subject'] ?? null;
		$subject      = is_array( $subject_list ) && array_is_list( $subject_list ) && 1 === count( $subject_list ) && is_array( $subject_list[0] )
			? $subject_list[0]
			: [];
		$digest_map   = is_array( $subject['digest'] ?? null ) ? $subject['digest'] : [];
		$before_map   = is_array( $predicate['before'] ?? null ) ? $predicate['before'] : [];
		$after_map    = is_array( $predicate['after'] ?? null ) ? $predicate['after'] : [];
		$governance   = is_array( $predicate['governance'] ?? null ) ? $predicate['governance'] : [];
		$actor        = is_array( $predicate['actor'] ?? null ) ? $predicate['actor'] : [];
		$timestamps   = is_array( $predicate['timestamps'] ?? null ) ? $predicate['timestamps'] : [];
		$surface      = is_string( $predicate['surface'] ?? null ) ? $predicate['surface'] : '';
		$scope        = is_string( $subject['scope'] ?? null ) ? $subject['scope'] : '';
		$subject_name = is_string( $subject['name'] ?? null ) ? $subject['name'] : '';
		$before       = is_string( $before_map['sha256'] ?? null ) ? $before_map['sha256'] : '';
		$after        = is_string( $after_map['sha256'] ?? null ) ? $after_map['sha256'] : '';
		$subject_dig  = is_string( $digest_map['sha256'] ?? null ) ? $digest_map['sha256'] : '';
		$id           = is_string( $predicate['attestationId'] ?? null ) ? $predicate['attestationId'] : '';
		$site_url     = is_string( $site['url'] ?? null ) ? $site['url'] : '';
		$decision     = is_string( $predicate['decision'] ?? null ) ? $predicate['decision'] : '';
		$reverts      = self::nullable_id( $predicate['revertsAttestationId'] ?? null );
		$supersedes   = self::nullable_id( $predicate['supersedesAttestationId'] ?? null );
		$schema_valid = self::exact_keys( $statement, [ '_type', 'subject', 'predicateType', 'predicate' ] )
			&& self::exact_keys( $subject, [ 'name', 'scope', 'digest' ] )
			&& self::exact_keys( $digest_map, [ 'sha256' ] )
			&& self::exact_keys(
				$predicate,
				[
					'attestationId',
					'schemaVersion',
					'surface',
					'governance',
					'operations',
					'before',
					'after',
					'freshnessSignature',
					'actor',
					'decision',
					'timestamps',
					'site',
					'revertsAttestationId',
					'supersedesAttestationId',
					'relatedActivityId',
				]
			)
			&& self::exact_keys( $governance, [ 'claim', 'lane', 'approvalSurface', 'executor' ] )
			&& self::exact_keys( $before_map, [ 'sha256' ] )
			&& self::exact_keys( $after_map, [ 'sha256' ] )
			&& self::exact_keys( $actor, [ 'role', 'proposerVia' ] )
			&& self::exact_keys( $timestamps, [ 'requestedAt', 'decidedAt' ] )
			&& self::exact_keys( $site, [ 'url', 'keyId' ] )
			&& self::STATEMENT_TYPE === ( $statement['_type'] ?? null )
			&& StatementBuilder::PREDICATE_TYPE === ( $statement['predicateType'] ?? null )
			&& 1 === ( $predicate['schemaVersion'] ?? null )
			&& is_string( $predicate['freshnessSignature'] ?? null )
			&& is_string( $actor['role'] ?? null )
			&& is_string( $actor['proposerVia'] ?? null )
			&& is_string( $timestamps['requestedAt'] ?? null )
			&& is_string( $timestamps['decidedAt'] ?? null )
			&& ( null === ( $predicate['relatedActivityId'] ?? null ) || is_string( $predicate['relatedActivityId'] ) )
			&& self::valid_id( $id )
			&& self::valid_profile( $surface, $scope, $subject_name, $governance )
			&& StatementBuilder::operations_match_profile(
				$predicate['operations'] ?? null,
				(string) ( $governance['lane'] ?? '' )
			)
			&& self::valid_digest( $before )
			&& self::valid_digest( $after )
			&& $after === $subject_dig
			&& in_array( $decision, [ 'approve', 'revert' ], true )
			&& self::valid_relationship( $decision, $reverts, $supersedes )
			&& '' !== $key_id
			&& null !== self::normalize_site_url( $site_url )
			&& ( ! array_key_exists( 'key_id', $envelope ) || ( is_string( $envelope['key_id'] ) && $key_id === $envelope['key_id'] ) )
			&& self::valid_nullable_id( $predicate['revertsAttestationId'] ?? null )
			&& self::valid_nullable_id( $predicate['supersedesAttestationId'] ?? null );

		if ( ! $schema_valid ) {
			$outcomes[] = 'statement_invalid';
		}

		$expected_site = self::normalize_site_url( $expected_site_url );
		$signed_site   = self::normalize_site_url( $site_url );
		$identity_ok   = $id === trim( $expected_attestation_id )
			&& null !== $expected_site
			&& $expected_site === $signed_site;

		if ( ! $identity_ok ) {
			$outcomes[] = 'attestation_identity_mismatch';
		}

		$claims = [
			'attestationId'           => $id,
			'siteUrl'                 => $site_url,
			'keyId'                   => $key_id,
			'surface'                 => $surface,
			'subjectName'             => $subject_name,
			'subjectScope'            => $scope,
			'beforeDigest'            => $before,
			'afterDigest'             => $after,
			'decision'                => $decision,
			'revertsAttestationId'    => $reverts,
			'supersedesAttestationId' => $supersedes,
		];

		return [
			'valid'    => $schema_valid && $identity_ok,
			'outcomes' => $outcomes,
			'claims'   => $claims,
		];
	}

	/**
	 * @param array<string, mixed> $governance
	 */
	private static function valid_profile( string $surface, string $scope, string $subject_name, array $governance ): bool {
		if ( '' === $subject_name ) {
			return false;
		}

		$profile = match ( $surface ) {
			'global-styles' => [ [ 'global-styles' ], 'external-style-apply-v1', 'bounded-server-style-apply' ],
			'style-book'    => [ [ 'global-styles', 'style-book-branch' ], 'external-style-apply-v1', 'bounded-server-style-apply' ],
			'template'      => [ [ 'template' ], 'external-template-apply-v1', 'bounded-server-template-apply' ],
			'template-part' => [ [ 'template-part' ], 'external-template-part-apply-v1', 'bounded-server-template-part-apply' ],
			default         => null,
		};

		return is_array( $profile )
			&& in_array( $scope, $profile[0], true )
			&& self::GOVERNANCE_CLAIM === ( $governance['claim'] ?? null )
			&& $profile[1] === ( $governance['lane'] ?? null )
			&& self::APPROVAL_SURFACE === ( $governance['approvalSurface'] ?? null )
			&& $profile[2] === ( $governance['executor'] ?? null );
	}

	private static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^att_[A-Za-z0-9]+$/', $id );
	}

	private static function nullable_id( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	private static function valid_nullable_id( mixed $value ): bool {
		return null === $value || ( is_string( $value ) && self::valid_id( $value ) );
	}

	private static function valid_digest( string $digest ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $digest );
	}

	private static function valid_relationship( string $decision, ?string $reverts, ?string $supersedes ): bool {
		if ( 'revert' === $decision ) {
			return null !== $reverts && null === $supersedes;
		}

		return 'approve' === $decision && null === $reverts;
	}

	/**
	 * @param array<string|int, mixed> $value
	 * @param list<string>             $expected
	 */
	private static function exact_keys( array $value, array $expected ): bool {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	private static function normalize_site_url( string $url ): ?string {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parts = \wp_parse_url( trim( $url ) );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The standalone verifier intentionally runs without WordPress loaded.
			$parts = parse_url( trim( $url ) );
		}

		if (
			! is_array( $parts )
			|| ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$path   = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$port_s = null !== $port && ! ( 'http' === $scheme && 80 === $port ) && ! ( 'https' === $scheme && 443 === $port )
			? ':' . $port
			: '';

		return $scheme . '://' . $host . $port_s . $path;
	}

	/**
	 * @param array{keys?: array<int, array<string, mixed>>} $jwks
	 */
	private static function public_key_for( array $jwks, string $key_id ): ?string {
		foreach ( $jwks['keys'] ?? [] as $jwk ) {
			if ( ! is_array( $jwk ) ) {
				continue;
			}

			if (
				( $jwk['kid'] ?? '' ) === $key_id
				&& 'OKP' === ( $jwk['kty'] ?? '' )
				&& 'Ed25519' === ( $jwk['crv'] ?? '' )
				&& 'sig' === ( $jwk['use'] ?? '' )
				&& 'EdDSA' === ( $jwk['alg'] ?? '' )
			) {
				$encoded = is_string( $jwk['x'] ?? null ) ? $jwk['x'] : '';
				$public  = self::b64url_decode( $encoded );

				return SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $public ) ? $public : null;
			}
		}

		return null;
	}

	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);

		return false === $decoded ? '' : $decoded;
	}

	private function __construct() {}
}
