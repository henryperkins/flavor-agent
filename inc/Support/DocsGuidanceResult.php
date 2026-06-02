<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class DocsGuidanceResult {

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 * @return array<string, mixed>
	 */
	public static function from_guidance( array $guidance, string $mode, string $transport, array $options = [] ): array {
		$normalized_guidance = self::normalize_guidance( $guidance );
		$freshness_values    = self::extract_freshness_values( $normalized_guidance );
		$source_types        = self::extract_source_types( $normalized_guidance );
		$coverage            = self::normalize_coverage( $options['sourceCoverage'] ?? [] );
		$requires_coverage   = ! empty( $options['requireCurrentSourceCoverage'] );
		$status              = self::resolve_status( $normalized_guidance, $freshness_values, $coverage, $requires_coverage );

		return [
			'status'      => $status,
			'mode'        => sanitize_key( $mode ),
			'transport'   => sanitize_key( $transport ),
			'guidance'    => $normalized_guidance,
			'sourceTypes' => $source_types,
			'freshness'   => $freshness_values,
			'coverage'    => $coverage,
			'fingerprint' => self::fingerprint( $normalized_guidance, $status, $mode, $coverage ),
		];
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<int, array<string, mixed>>
	 */
	public static function guidance( array $result ): array {
		return is_array( $result['guidance'] ?? null ) ? $result['guidance'] : [];
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	public static function public_summary( array $result ): array {
		return [
			'status'      => sanitize_key( (string) ( $result['status'] ?? 'unavailable' ) ),
			'mode'        => sanitize_key( (string) ( $result['mode'] ?? '' ) ),
			'transport'   => sanitize_key( (string) ( $result['transport'] ?? '' ) ),
			'sourceTypes' => array_values( array_map( 'sanitize_key', (array) ( $result['sourceTypes'] ?? [] ) ) ),
			'freshness'   => array_values( array_map( 'sanitize_key', (array) ( $result['freshness'] ?? [] ) ) ),
			'coverage'    => self::normalize_coverage( $result['coverage'] ?? [] ),
			'fingerprint' => sanitize_text_field( (string) ( $result['fingerprint'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $result
	 */
	public static function is_actionable( array $result ): bool {
		return in_array(
			sanitize_key( (string) ( $result['status'] ?? 'unavailable' ) ),
			[ 'grounded', 'degraded', 'stale' ],
			true
		);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	public static function unavailable_error( array $result ): \WP_Error {
		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires when the docs-grounding gate blocks a recommendation request.
			 *
			 * Listeners can record diagnostics or schedule recovery work. Carries the
			 * full normalized result (including coverage) so observers can branch on
			 * the specific failure mode (missing official guidance vs. coverage drift).
			 *
			 * @param array<string, mixed> $result Normalized docs-grounding result.
			 */
			do_action( 'flavor_agent_docs_grounding_unavailable', $result );
		}

		return new \WP_Error(
			'flavor_agent_docs_grounding_unavailable',
			'Flavor Agent could not verify current WordPress developer guidance for this recommendation. Try again after Developer Docs grounding refreshes.',
			[
				'status'        => 503,
				'docsGrounding' => self::public_summary( $result ),
			]
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function fingerprint( array $guidance, string $status, string $mode, array $coverage = [] ): string {
		$payload = [
			'policy'   => DocsGroundingSourcePolicy::current_policy_fingerprint(),
			'status'   => $status,
			'coverage' => [
				'status'                 => (string) ( $coverage['status'] ?? '' ),
				'hasDeveloperDocs'       => ! empty( $coverage['hasDeveloperDocs'] ),
				'hasCurrentReleaseCycle' => ! empty( $coverage['hasCurrentReleaseCycle'] ),
				'sourceTypes'            => (array) ( $coverage['sourceTypes'] ?? [] ),
				'freshness'              => (array) ( $coverage['freshness'] ?? [] ),
			],
			'guidance' => array_map(
				static fn ( array $chunk ): array => [
					'url'         => (string) ( $chunk['url'] ?? '' ),
					'sourceType'  => (string) ( $chunk['sourceType'] ?? '' ),
					'contentHash' => (string) ( $chunk['contentHash'] ?? '' ),
					'retrievedAt' => (string) ( $chunk['retrievedAt'] ?? '' ),
					'publishedAt' => (string) ( $chunk['publishedAt'] ?? '' ),
					'freshness'   => (string) ( $chunk['freshness'] ?? '' ),
				],
				$guidance
			),
		];

		$encoded = wp_json_encode( $payload );

		return hash( 'sha256', false === $encoded ? 'docs-grounding' : $encoded );
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_guidance( array $guidance ): array {
		return array_values(
			array_filter(
				$guidance,
				static fn ( $chunk ): bool => is_array( $chunk )
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 * @return array<int, string>
	 */
	private static function extract_freshness_values( array $guidance ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( array $chunk ): string => sanitize_key( (string) ( $chunk['freshness'] ?? '' ) ),
						$guidance
					)
				)
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 * @return array<int, string>
	 */
	private static function extract_source_types( array $guidance ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( array $chunk ): string => sanitize_key( (string) ( $chunk['sourceType'] ?? '' ) ),
						$guidance
					)
				)
			)
		);
	}

	/**
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
	private static function normalize_coverage( mixed $coverage ): array {
		if ( ! is_array( $coverage ) ) {
			$coverage = [];
		}

		return [
			'status'                 => sanitize_key( (string) ( $coverage['status'] ?? 'unknown' ) ),
			'hasDeveloperDocs'       => ! empty( $coverage['hasDeveloperDocs'] ),
			'hasCurrentReleaseCycle' => ! empty( $coverage['hasCurrentReleaseCycle'] ),
			'sourceTypes'            => array_values( array_map( 'sanitize_key', (array) ( $coverage['sourceTypes'] ?? [] ) ) ),
			'freshness'              => array_values( array_map( 'sanitize_key', (array) ( $coverage['freshness'] ?? [] ) ) ),
			'checkedAt'              => sanitize_text_field( (string) ( $coverage['checkedAt'] ?? '' ) ),
			'errorCode'              => sanitize_key( (string) ( $coverage['errorCode'] ?? '' ) ),
			'errorMessage'           => sanitize_text_field( (string) ( $coverage['errorMessage'] ?? '' ) ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 * @param array<int, string>              $freshness_values
	 */
	private static function resolve_status( array $guidance, array $freshness_values, array $coverage = [], bool $requires_coverage = false ): string {
		if ( [] === $guidance || ! self::has_official_guidance( $guidance ) ) {
			return 'unavailable';
		}

		if ( $requires_coverage && self::coverage_indicates_hard_block( $coverage ) ) {
			return 'unavailable';
		}

		if ( [] === $freshness_values ) {
			return 'degraded';
		}

		if ( in_array( 'current', $freshness_values, true ) ) {
			return 'grounded';
		}

		if ( in_array( 'unknown', $freshness_values, true ) ) {
			return 'degraded';
		}

		return 'stale';
	}

	/**
	 * Hard-block only when the coverage probe shows no trusted stable Developer Docs
	 * (`missing-developer-docs`) or a probe transport failure (`unavailable`). Missing
	 * release-cycle currency alone degrades-to-warn: the coverage summary still carries
	 * the warning downstream, so the surface proceeds with a "review current docs" notice.
	 *
	 * @param array<string, mixed> $coverage
	 */
	private static function coverage_indicates_hard_block( array $coverage ): bool {
		return in_array(
			sanitize_key( (string) ( $coverage['status'] ?? '' ) ),
			[ 'missing-developer-docs', 'unavailable' ],
			true
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function official_source_types(): array {
		return [
			DocsGroundingSourcePolicy::SOURCE_DEVELOPER_DOCS,
			DocsGroundingSourcePolicy::SOURCE_DEVELOPER_BLOG,
			DocsGroundingSourcePolicy::SOURCE_MAKE_CORE,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function has_official_guidance( array $guidance ): bool {
		foreach ( $guidance as $chunk ) {
			if ( in_array( sanitize_key( (string) ( $chunk['sourceType'] ?? '' ) ), self::official_source_types(), true ) ) {
				return true;
			}
		}

		return false;
	}
}
