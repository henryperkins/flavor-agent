<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Deterministic canonical view + sha256 digest of a Global Styles user config.
 * Single source of truth for StyleApplyExecutor's drift check and Ring III
 * attestation digests, so the two can never diverge. The canonicalization
 * rules here are published as the verifier spec — see
 * docs/reference/ring-iii-attestation-design.md §6.
 */
final class Canonicalizer {

	/**
	 * @param array<string, mixed> $config
	 * @return array{settings: mixed, styles: mixed}
	 */
	public static function comparable_config( array $config ): array {
		return [
			'settings' => self::canonicalize_values_deep( self::sort_keys_deep( is_array( $config['settings'] ?? null ) ? $config['settings'] : [] ) ),
			'styles'   => self::canonicalize_values_deep( self::sort_keys_deep( is_array( $config['styles'] ?? null ) ? $config['styles'] : [] ) ),
		];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function canonical_bytes( array $config ): string {
		return (string) wp_json_encode( self::comparable_config( $config ) );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function digest( array $config ): string {
		return hash( 'sha256', self::canonical_bytes( $config ) );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function subject_digest( array $config, string $scope, string $block_name = '' ): string {
		$target = ( 'style-book-branch' === $scope && '' !== $block_name )
			? self::block_branch( $config, $block_name )
			: $config;

		return self::digest( $target );
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function block_branch( array $config, string $block_name ): array {
		$branch = self::read_path(
			is_array( $config['styles'] ?? null ) ? $config['styles'] : [],
			[ 'blocks', $block_name ]
		);

		if ( null === $branch ) {
			return [];
		}

		return [
			'styles' => [
				'blocks' => [
					$block_name => $branch,
				],
			],
		];
	}

	public static function sort_keys_deep( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( [] === $value ) {
			return [];
		}

		if ( self::is_list( $value ) ) {
			return array_map( [ self::class, 'sort_keys_deep' ], $value );
		}

		ksort( $value );

		$sorted = [];

		foreach ( $value as $key => $entry ) {
			$sorted[ $key ] = self::sort_keys_deep( $entry );
		}

		return $sorted;
	}

	/**
	 * Normalize preset/custom references so two serializations of the same value
	 * compare equal. WordPress persists the user Global Styles post with the
	 * resolved CSS custom property (var(--wp--preset--color--x)), while Flavor
	 * Agent records the theme.json reference it wrote (var:preset|color|x).
	 * Without this, drift-safe undo reads false drift on every preset-valued
	 * apply because the live read-back never byte-matches the recorded snapshot.
	 */
	public static function canonicalize_values_deep( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return self::canonicalize_style_value( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = [];

		foreach ( $value as $key => $entry ) {
			$normalized[ $key ] = self::canonicalize_values_deep( $entry );
		}

		return $normalized;
	}

	/**
	 * Map a resolved CSS custom property (var(--wp--preset--color--x)) back to
	 * its theme.json reference form (var:preset|color|x). Non-preset strings pass
	 * through unchanged so only logically-equivalent serializations collapse.
	 */
	public static function canonicalize_style_value( string $value ): string {
		if ( 1 === preg_match( '/^var\(\s*--wp--(.+?)\s*\)$/', $value, $matches ) ) {
			return 'var:' . str_replace( '--', '|', $matches[1] );
		}

		return $value;
	}

	/**
	 * @param array<int, int|string> $path
	 */
	private static function read_path( mixed $value, array $path ): mixed {
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function is_list( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}

		$index = 0;

		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}

			++$index;
		}

		return true;
	}

	private function __construct() {}
}
