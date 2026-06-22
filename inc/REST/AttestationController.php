<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Apply\StyleApplyExecutor;
use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Repository;

/**
 * Public, unauthenticated read surface for Ring III attestations. The signed
 * statement and JWKS verify independently; subject-state is site-served, so the
 * live-match check carries a present-state trust component.
 */
final class AttestationController {

	private const NAMESPACE = 'flavor-agent/v1';

	public static function register_routes(): void {
		$self = new self();

		\register_rest_route(
			self::NAMESPACE,
			'/attestations/keys',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ $self, 'get_keys' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/attestations/(?P<id>att_[A-Za-z0-9]+)',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ $self, 'get_attestation' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/attestations/(?P<id>att_[A-Za-z0-9]+)/subject-state',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ $self, 'get_subject_state' ],
			]
		);
	}

	public function get_keys( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		return new \WP_REST_Response( KeyManager::jwks(), 200 );
	}

	public function get_attestation( \WP_REST_Request $request ): \WP_REST_Response {
		$row = Repository::find( (string) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$reverted_by = Repository::find_by_reverts( (string) $row['attestation_id'] );

		return new \WP_REST_Response(
			[
				'statement_b64'              => KeyManager::b64url( (string) $row['statement_bytes'] ),
				'signature_b64'              => (string) $row['signature_b64'],
				'key_id'                     => (string) $row['key_id'],
				'reverted_by_attestation_id' => $reverted_by['attestation_id'] ?? null,
				'statement_json'             => json_decode( (string) $row['statement_bytes'], true ),
			],
			200
		);
	}

	public function get_subject_state( \WP_REST_Request $request ): \WP_REST_Response {
		$row = Repository::find( (string) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$name       = (string) $row['subject_name'];
		$scope      = (string) $row['subject_scope'];
		$hash_pos   = strpos( $name, '#' );
		$block_name = false !== $hash_pos ? substr( $name, $hash_pos + 1 ) : '';
		$base_name  = false !== $hash_pos ? substr( $name, 0, $hash_pos ) : $name;
		$gs_id      = preg_replace( '/^wp_global_styles:/', '', $base_name );
		$resolved   = StyleApplyExecutor::resolve_user_global_styles( is_string( $gs_id ) ? $gs_id : '' );

		if ( \is_wp_error( $resolved ) ) {
			return new \WP_REST_Response( [ 'error' => 'subject_unavailable' ], 409 );
		}

		$target = ( 'style-book-branch' === $scope && '' !== $block_name )
			? Canonicalizer::block_branch( $resolved['config'], $block_name )
			: $resolved['config'];

		return new \WP_REST_Response(
			[
				'subject_canonical_b64' => KeyManager::b64url( Canonicalizer::canonical_bytes( $target ) ),
				'subject_digest'        => Canonicalizer::digest( $target ),
				'scope'                 => $scope,
			],
			200
		);
	}
}
