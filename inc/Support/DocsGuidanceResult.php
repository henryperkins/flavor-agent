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
		$normalized   = self::normalize_guidance( $guidance );
		$source_types = self::extract_source_types( $normalized );

		return [
			'mode'        => sanitize_key( $mode ),
			'transport'   => sanitize_key( $transport ),
			'guidance'    => null === $prompt_guidance ? $normalized : self::normalize_guidance( $prompt_guidance ),
			'sourceTypes' => $source_types,
			'count'       => count( $normalized ),
			'available'   => [] !== $normalized,
			'fingerprint' => self::fingerprint( $normalized ),
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
			'available'   => ! empty( $result['available'] ),
			'sourceTypes' => array_values( array_map( 'sanitize_key', (array) ( $result['sourceTypes'] ?? [] ) ) ),
			'count'       => (int) ( $result['count'] ?? 0 ),
		];
	}

	/**
	 * Content fingerprint over the attached guidance; feeds the resolved-signature
	 * freshness checks so applies re-validate when grounding content changes.
	 * Chunks are sorted before hashing so a backend returning the same documents
	 * in a different order doesn't read as a content change.
	 *
	 * @param array<int, array<string, mixed>> $guidance
	 */
	private static function fingerprint( array $guidance ): string {
		$payload = array_map(
			static fn ( array $chunk ): array => [
				'url'         => (string) ( $chunk['url'] ?? '' ),
				'sourceType'  => (string) ( $chunk['sourceType'] ?? '' ),
				'contentHash' => (string) ( $chunk['contentHash'] ?? '' ),
			],
			$guidance
		);

		usort(
			$payload,
			static fn ( array $left, array $right ): int => strcmp(
				implode( '|', $left ),
				implode( '|', $right )
			)
		);

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
