<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Trusted server declaration, stamped only after package/source verification. */
final class FixtureExportProvenance {

	public static function resolve(): array|\WP_Error {
		global $wp_version;
		$declaration = defined( 'FLAVOR_AGENT_FIXTURE_EXPORT_PROVENANCE' ) ? constant( 'FLAVOR_AGENT_FIXTURE_EXPORT_PROVENANCE' ) : null;
		if ( ! is_array( $declaration ) || true !== ( $declaration['verified'] ?? false )
			|| ! RecommendationFixtureSchema::object_keys( $declaration, [ 'verified', 'kind', 'implementationSha', 'publicVersions' ] ) ) {
			return self::unavailable();
		}
		$provenance           = array_diff_key( $declaration, [ 'verified' => true ] );
		$provenance['config'] = [
			'surfaces'    => RecommendationOutcome::SURFACES,
			'rankingMode' => 'static',
		];
		if ( ! RecommendationFixtureSchema::provenance( $provenance )
			|| ! defined( 'FLAVOR_AGENT_VERSION' ) || FLAVOR_AGENT_VERSION !== $provenance['publicVersions']['plugin']
			|| ! is_string( $wp_version ) || $wp_version !== $provenance['publicVersions']['wordpress'] ) {
			return self::unavailable();
		}
		$gutenberg = $provenance['publicVersions']['gutenberg'] ?? null;
		if ( ( defined( 'GUTENBERG_VERSION' ) && GUTENBERG_VERSION !== $gutenberg ) || ( ! defined( 'GUTENBERG_VERSION' ) && null !== $gutenberg ) ) {
			return self::unavailable();
		}
		return $provenance;
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'flavor_agent_fixture_provenance_unverified', 'Verify the exact running source/package and public runtime versions, then configure the server-owned fixture provenance declaration.', [ 'status' => 409 ] );
	}
}
