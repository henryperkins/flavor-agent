<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class DocsGuidanceResult {

	/**
	 * The summary fields (`available`, `count`, `sourceTypes`, `fingerprint`) always
	 * describe `$guidance` — the developer-docs chunks. When `$prompt_guidance` is
	 * provided (docs merged with advisory roadmap chunks), it replaces only the
	 * `guidance` payload used for prompt assembly, so advisory-only chunks can't
	 * mask a "running without docs grounding" state.
	 *
	 * @param array<int, array<string, mixed>>      $guidance
	 * @param array<int, array<string, mixed>>|null $prompt_guidance
	 * @return array<string, mixed>
	 */
	public static function from_guidance( array $guidance, string $mode, string $transport, ?array $prompt_guidance = null ): array {
		$mode                = sanitize_key( $mode );
		$transport           = sanitize_key( $transport );
		$normalized          = self::normalize_guidance( $guidance );
		$source_types        = self::extract_source_types( $normalized );
		$content_fingerprint = self::build_content_fingerprint( $normalized );
		$runtime_fingerprint = self::build_runtime_fingerprint( $normalized, $mode, $transport, $content_fingerprint );

		return [
			'mode'               => $mode,
			'transport'          => $transport,
			'guidance'           => null === $prompt_guidance ? $normalized : self::normalize_guidance( $prompt_guidance ),
			'sourceTypes'        => $source_types,
			'count'              => count( $normalized ),
			'available'          => [] !== $normalized,
			'fingerprint'        => $content_fingerprint,
			'contentFingerprint' => $content_fingerprint,
			'runtimeFingerprint' => $runtime_fingerprint,
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
	 * @return array{available: bool, sourceTypes: array<int, string>, count: int}
	 */
	public static function public_summary( array $result ): array {
		return [
			'available'          => ! empty( $result['available'] ),
			'sourceTypes'        => array_values( array_map( 'sanitize_key', (array) ( $result['sourceTypes'] ?? [] ) ) ),
			'count'              => (int) ( $result['count'] ?? 0 ),
			'contentFingerprint' => self::content_fingerprint( $result ),
			'runtimeFingerprint' => self::runtime_fingerprint( $result ),
		];
	}

	/**
	 * Return the docs content/applicability fingerprint from a result payload.
	 *
	 * @param array<string, mixed> $result
	 */
	public static function content_fingerprint( array $result ): string {
		return sanitize_text_field(
			(string) ( $result['contentFingerprint'] ?? $result['fingerprint'] ?? '' )
		);
	}

	/**
	 * Return the docs runtime/diagnostics fingerprint from a result payload.
	 *
	 * @param array<string, mixed> $result
	 */
	public static function runtime_fingerprint( array $result ): string {
		return sanitize_text_field( (string) ( $result['runtimeFingerprint'] ?? '' ) );
	}

	/**
	 * Content fingerprint over the attached guidance; feeds the resolved-signature
	 * freshness checks so applies re-validate when grounding content changes.
	 * Chunks are sorted before hashing so a backend returning the same documents
	 * in a different order doesn't read as a content change.
	 *
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function build_content_fingerprint( array $guidance ): string {
		$payload = array_map(
			static fn ( array $chunk ): array => [
				'url'         => (string) ( $chunk['url'] ?? '' ),
				'sourceType'  => (string) ( $chunk['sourceType'] ?? '' ),
				'contentHash' => (string) ( $chunk['contentHash'] ?? '' ),
				'publishedAt' => (string) ( $chunk['publishedAt'] ?? '' ),
				'freshness'   => (string) ( $chunk['freshness'] ?? '' ),
				'status'      => (string) ( $chunk['status'] ?? '' ),
				'coverage'    => self::normalize_fingerprint_value( $chunk['coverage'] ?? null ),
			],
			$guidance
		);

		self::sort_fingerprint_payload( $payload );

		$encoded = wp_json_encode( $payload );

		return hash( 'sha256', false === $encoded ? 'docs-grounding' : $encoded );
	}

	/**
	 * Runtime fingerprint for diagnostics. Recommendation applicability must use
	 * the content fingerprint instead so cache refresh timing does not stale applies.
	 *
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function build_runtime_fingerprint( array $guidance, string $mode, string $transport, string $content_fingerprint ): string {
		$chunks = array_map(
			static fn ( array $chunk ): array => [
				'url'               => (string) ( $chunk['url'] ?? '' ),
				'sourceType'        => (string) ( $chunk['sourceType'] ?? '' ),
				'contentHash'       => (string) ( $chunk['contentHash'] ?? '' ),
				'retrievedAt'       => (string) ( $chunk['retrievedAt'] ?? '' ),
				'score'             => isset( $chunk['score'] ) ? (float) $chunk['score'] : null,
				'runtime'           => self::normalize_fingerprint_value( $chunk['runtime'] ?? null ),
				'cacheStatus'       => (string) ( $chunk['cacheStatus'] ?? '' ),
				'coverageCheckedAt' => (string) ( $chunk['coverageCheckedAt'] ?? '' ),
			],
			$guidance
		);

		self::sort_fingerprint_payload( $chunks );

		$encoded = wp_json_encode(
			[
				'mode'               => $mode,
				'transport'          => $transport,
				'contentFingerprint' => $content_fingerprint,
				'chunks'             => $chunks,
			]
		);

		return hash( 'sha256', false === $encoded ? 'docs-grounding-runtime' : $encoded );
	}

	/**
	 * @param array<int, array<string, mixed>> $payload
	 */
	private static function sort_fingerprint_payload( array &$payload ): void {
		usort(
			$payload,
			static fn ( array $left, array $right ): int => strcmp(
				(string) wp_json_encode( $left ),
				(string) wp_json_encode( $right )
			)
		);
	}

	/**
	 * @return scalar|array<string, mixed>|array<int, mixed>|null
	 */
	private static function normalize_fingerprint_value( $value ) {
		if ( is_array( $value ) ) {
			$normalized = [];

			foreach ( $value as $key => $item ) {
				$normalized[ $key ] = self::normalize_fingerprint_value( $item );
			}

			if ( array_keys( $normalized ) !== range( 0, count( $normalized ) - 1 ) ) {
				ksort( $normalized );
			}

			return $normalized;
		}

		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return (string) $value;
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
}
