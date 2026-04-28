<?php

declare(strict_types=1);

namespace FlavorAgent\Guidelines;

final class RepositoryResolver {

	public static function resolve(): GuidelinesRepository {
		$filtered = apply_filters( 'flavor_agent_guidelines_repository', null );

		if ( $filtered instanceof GuidelinesRepository ) {
			return $filtered;
		}

		$post_type = CoreGuidelinesRepository::available_post_type();

		if ( null === $post_type ) {
			return new LegacyGuidelinesRepository();
		}

		$core   = new CoreGuidelinesRepository( $post_type );
		$legacy = new LegacyGuidelinesRepository();

		if ( self::has_any( $core->get_all() ) || ! self::has_any( $legacy->get_all() ) ) {
			return $core;
		}

		return $legacy;
	}

	public static function core_available(): bool {
		return CoreGuidelinesRepository::is_available();
	}

	/**
	 * @param array{site: string, copy: string, images: string, additional: string, blocks: array<string, string>} $guidelines
	 */
	public static function has_any( array $guidelines ): bool {
		foreach ( [ 'site', 'copy', 'images', 'additional' ] as $category ) {
			if ( '' !== (string) ( $guidelines[ $category ] ?? '' ) ) {
				return true;
			}
		}

		return [] !== ( $guidelines['blocks'] ?? [] );
	}
}
