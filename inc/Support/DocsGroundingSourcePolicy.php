<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class DocsGroundingSourcePolicy {

	public const SOURCE_DEVELOPER_DOCS = 'developer-docs';
	public const SOURCE_DEVELOPER_BLOG = 'developer-blog';
	public const SOURCE_MAKE_CORE      = 'make-core';

	private const SECONDS_PER_DAY             = 86400;
	private const CURRENT_RELEASE_PUBLIC_DATE = '2026-05-20T00:00:00Z';

	private const TRUSTED_SCOPES = [
		self::SOURCE_DEVELOPER_DOCS => [
			[
				'host'       => 'developer.wordpress.org',
				'pathPrefix' => '/block-editor/',
			],
			[
				'host'       => 'developer.wordpress.org',
				'pathPrefix' => '/rest-api/',
			],
			[
				'host'       => 'developer.wordpress.org',
				'pathPrefix' => '/themes/',
			],
			[
				'host'       => 'developer.wordpress.org',
				'pathPrefix' => '/reference/',
			],
		],
		self::SOURCE_DEVELOPER_BLOG => [
			[
				'host'       => 'developer.wordpress.org',
				'pathPrefix' => '/news/',
			],
		],
		self::SOURCE_MAKE_CORE      => [
			[
				'host'       => 'make.wordpress.org',
				'pathPrefix' => '/core/',
			],
		],
	];

	public static function classify_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if (
			'https' !== $scheme ||
			'' === $host ||
			'' === $path ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ||
			self::path_contains_untrusted_segments( $path )
		) {
			return '';
		}

		$normalized_path = preg_replace( '#/+#', '/', $path );
		$normalized_path = '/' . ltrim( false === $normalized_path ? '' : $normalized_path, '/' );

		foreach ( self::TRUSTED_SCOPES as $source_type => $scopes ) {
			foreach ( $scopes as $scope ) {
				$scope_root = rtrim( $scope['pathPrefix'], '/' );
				if (
					$host === $scope['host'] &&
					(
						$normalized_path === $scope_root ||
						str_starts_with( $normalized_path, $scope['pathPrefix'] )
					)
				) {
					return $source_type;
				}
			}
		}

		return '';
	}

	public static function is_trusted_url( string $url ): bool {
		return '' !== self::classify_url( $url );
	}

	public static function normalize_trusted_url( string $url ): string {
		if ( ! self::is_trusted_url( $url ) ) {
			return '';
		}

		$parts = wp_parse_url( trim( $url ) );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = preg_replace( '#/+#', '/', (string) ( $parts['path'] ?? '' ) );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return 'https://' . $host . '/' . ltrim( $path, '/' );
	}

	public static function freshness_status(
		string $source_type,
		string $retrieved_at = '',
		string $published_at = '',
		?int $now = null
	): string {
		$now       = null === $now ? time() : $now;
		$timestamp = self::best_freshness_timestamp( $source_type, $retrieved_at, $published_at );

		if ( $timestamp <= 0 ) {
			return 'unknown';
		}

		$max_age = match ( $source_type ) {
			self::SOURCE_MAKE_CORE => 21 * self::SECONDS_PER_DAY,
			self::SOURCE_DEVELOPER_BLOG => 45 * self::SECONDS_PER_DAY,
			self::SOURCE_DEVELOPER_DOCS => 90 * self::SECONDS_PER_DAY,
			default => 30 * self::SECONDS_PER_DAY,
		};

		return ( $now - $timestamp ) <= $max_age ? 'current' : 'stale';
	}

	public static function current_policy_fingerprint(): string {
		$payload = wp_json_encode( self::TRUSTED_SCOPES );

		return hash( 'sha256', false === $payload ? 'trusted-scopes' : $payload );
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 * @return array{
	 *   status: string,
	 *   hasDeveloperDocs: bool,
	 *   hasCurrentReleaseCycle: bool,
	 *   sourceTypes: array<int, string>,
	 *   freshness: array<int, string>,
	 *   checkedAt: string,
	 *   errorCode: string,
	 *   errorMessage: string
	 * }
	 */
	public static function source_coverage_summary( array $guidance, ?int $now = null ): array {
		$now                       = null === $now ? time() : $now;
		$source_types              = [];
		$freshness_values          = [];
		$has_developer_docs        = false;
		$has_current_release_cycle = false;

		foreach ( $guidance as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			$source_type = sanitize_key( (string) ( $chunk['sourceType'] ?? '' ) );
			if ( '' === $source_type ) {
				continue;
			}

			$freshness = self::chunk_freshness_for_coverage( $chunk, $source_type, $now );

			$source_types[]     = $source_type;
			$freshness_values[] = $freshness;

			if ( self::SOURCE_DEVELOPER_DOCS === $source_type ) {
				$has_developer_docs = true;
			}

			if ( self::release_cycle_source_satisfies_current_gate( $chunk, $source_type, $freshness ) ) {
				$has_current_release_cycle = true;
			}
		}

		$status        = 'current';
		$error_code    = '';
		$error_message = '';

		if ( ! $has_developer_docs ) {
			$status        = 'missing-developer-docs';
			$error_code    = 'missing_developer_docs';
			$error_message = 'Developer Docs grounding did not return a trusted stable Developer Docs source.';
		} elseif ( ! $has_current_release_cycle ) {
			$status        = 'missing-current-release-cycle';
			$error_code    = 'missing_current_release_cycle';
			$error_message = 'Developer Docs grounding is missing current WordPress release-cycle sources.';
		}

		return [
			'status'                 => $status,
			'hasDeveloperDocs'       => $has_developer_docs,
			'hasCurrentReleaseCycle' => $has_current_release_cycle,
			'sourceTypes'            => array_values( array_unique( array_filter( $source_types ) ) ),
			'freshness'              => array_values( array_unique( array_filter( $freshness_values ) ) ),
			'checkedAt'              => gmdate( 'Y-m-d H:i:s', $now ),
			'errorCode'              => $error_code,
			'errorMessage'           => $error_message,
		];
	}

	private static function best_freshness_timestamp( string $source_type, string $retrieved_at, string $published_at ): int {
		$retrieved = '' !== trim( $retrieved_at ) ? strtotime( $retrieved_at ) : false;
		$published = '' !== trim( $published_at ) ? strtotime( $published_at ) : false;

		if ( in_array( $source_type, [ self::SOURCE_MAKE_CORE, self::SOURCE_DEVELOPER_BLOG ], true ) ) {
			return false !== $published ? (int) $published : 0;
		}

		if ( self::SOURCE_DEVELOPER_DOCS === $source_type && false !== $retrieved ) {
			return (int) $retrieved;
		}

		if ( false === $retrieved && false === $published ) {
			return 0;
		}

		return max(
			false !== $retrieved ? (int) $retrieved : 0,
			false !== $published ? (int) $published : 0
		);
	}

	/**
	 * After WordPress 7.0 shipped, release-cycle sources from the public release
	 * date onward remain valid coverage even when the short rolling Make/Core or
	 * Developer Blog freshness windows age out between release bursts.
	 *
	 * @param array<string, mixed> $chunk
	 */
	private static function release_cycle_source_satisfies_current_gate( array $chunk, string $source_type, string $freshness ): bool {
		if ( ! in_array( $source_type, [ self::SOURCE_DEVELOPER_BLOG, self::SOURCE_MAKE_CORE ], true ) ) {
			return false;
		}

		if ( 'current' === $freshness ) {
			return true;
		}

		$published_at = trim( (string) ( $chunk['publishedAt'] ?? '' ) );
		$published    = '' !== $published_at ? strtotime( $published_at ) : false;
		$release_date = strtotime( self::CURRENT_RELEASE_PUBLIC_DATE );

		return false !== $published && false !== $release_date && (int) $published >= (int) $release_date;
	}

	/**
	 * @param array<string, mixed> $chunk
	 */
	private static function chunk_freshness_for_coverage( array $chunk, string $source_type, int $now ): string {
		if ( in_array( $source_type, [ self::SOURCE_MAKE_CORE, self::SOURCE_DEVELOPER_BLOG ], true ) ) {
			return self::freshness_status(
				$source_type,
				(string) ( $chunk['retrievedAt'] ?? '' ),
				(string) ( $chunk['publishedAt'] ?? '' ),
				$now
			);
		}

		$freshness = sanitize_key( (string) ( $chunk['freshness'] ?? '' ) );

		if ( '' !== $freshness ) {
			return $freshness;
		}

		return self::freshness_status(
			$source_type,
			(string) ( $chunk['retrievedAt'] ?? '' ),
			(string) ( $chunk['publishedAt'] ?? '' ),
			$now
		);
	}

	private static function path_contains_untrusted_segments( string $path ): bool {
		foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
			$decoded = rawurldecode( $segment );
			if (
				'.' === $decoded ||
				'..' === $decoded ||
				str_contains( $decoded, '/' ) ||
				str_contains( $decoded, '\\' )
			) {
				return true;
			}
		}

		return false;
	}
}
