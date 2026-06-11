<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class DocsGuidanceResult {

	/**
	 * @param array<int, array<string, mixed>> $guidance
	 * @return array<string, mixed>
	 */
	public static function from_guidance( array $guidance, string $mode, string $transport ): array {
		$normalized   = self::normalize_guidance( $guidance );
		$source_types = self::extract_source_types( $normalized );

		return [
			'mode'        => sanitize_key( $mode ),
			'transport'   => sanitize_key( $transport ),
			'guidance'    => $normalized,
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
