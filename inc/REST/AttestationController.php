<?php

declare(strict_types=1);

namespace FlavorAgent\REST;

use FlavorAgent\Apply\StyleApplyExecutor;
use FlavorAgent\Apply\TemplateApplyExecutor;
use FlavorAgent\Apply\TemplatePartApplyExecutor;
use FlavorAgent\Attestation\BlockContentCanonicalizer;
use FlavorAgent\Attestation\Canonicalizer;
use FlavorAgent\Attestation\KeyManager;
use FlavorAgent\Attestation\Repository;
use FlavorAgent\Attestation\Verifier;

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
		\register_rest_route(
			self::NAMESPACE,
			'/attestations/(?P<id>att_[A-Za-z0-9]+)/verification',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ $self, 'get_verification' ],
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

		$reverted_by   = Repository::find_by_reverts( (string) $row['attestation_id'] );
		$superseded_by = Repository::find_by_supersedes( (string) $row['attestation_id'] );

		return new \WP_REST_Response(
			[
				'statement_b64'                => KeyManager::b64url( (string) $row['statement_bytes'] ),
				'signature_b64'                => (string) $row['signature_b64'],
				'key_id'                       => (string) $row['key_id'],
				'reverted_by_attestation_id'   => $reverted_by['attestation_id'] ?? null,
				'superseded_by_attestation_id' => $superseded_by['attestation_id'] ?? null,
				'statement_json'               => json_decode( (string) $row['statement_bytes'], true ),
			],
			200
		);
	}

	public function get_subject_state( \WP_REST_Request $request ): \WP_REST_Response {
		$row = Repository::find( (string) $request->get_param( 'id' ) );

		if ( null === $row ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		return $this->build_subject_state_response( $row );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function build_subject_state_response( array $row ): \WP_REST_Response {
		return match ( (string) ( $row['surface'] ?? '' ) ) {
			'global-styles', 'style-book' => $this->build_style_subject_state_response( $row ),
			'template'                   => $this->build_template_subject_state_response( $row ),
			'template-part'              => $this->build_template_part_subject_state_response( $row ),
			default                      => self::subject_unavailable_response(),
		};
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function build_style_subject_state_response( array $row ): \WP_REST_Response {
		$name       = (string) $row['subject_name'];
		$scope      = (string) $row['subject_scope'];
		$hash_pos   = strpos( $name, '#' );
		$block_name = false !== $hash_pos ? substr( $name, $hash_pos + 1 ) : '';
		$base_name  = false !== $hash_pos ? substr( $name, 0, $hash_pos ) : $name;

		if ( ! str_starts_with( $base_name, 'wp_global_styles:' ) ) {
			return self::subject_unavailable_response();
		}

		$resolved = StyleApplyExecutor::resolve_user_global_styles( substr( $base_name, strlen( 'wp_global_styles:' ) ) );

		if ( \is_wp_error( $resolved ) ) {
			return self::subject_unavailable_response();
		}

		$config = is_array( $resolved['config'] ?? null ) ? $resolved['config'] : [];
		$target = ( 'style-book-branch' === $scope && '' !== $block_name )
			? Canonicalizer::block_branch( $config, $block_name )
			: $config;

		return self::subject_state_response( Canonicalizer::canonical_bytes( $target ), $scope );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function build_template_subject_state_response( array $row ): \WP_REST_Response {
		$ref = self::subject_ref( (string) ( $row['subject_name'] ?? '' ), 'wp_template:' );

		if ( '' === $ref ) {
			return self::subject_unavailable_response();
		}

		$content = TemplateApplyExecutor::resolve_live_content( $ref );

		if ( is_wp_error( $content ) ) {
			return self::subject_unavailable_response();
		}

		return self::subject_state_response(
			BlockContentCanonicalizer::bytes( $content ),
			(string) ( $row['subject_scope'] ?? 'template' )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function build_template_part_subject_state_response( array $row ): \WP_REST_Response {
		$ref = self::subject_ref( (string) ( $row['subject_name'] ?? '' ), 'wp_template_part:' );

		if ( '' === $ref ) {
			return self::subject_unavailable_response();
		}

		$content = TemplatePartApplyExecutor::resolve_live_content( $ref );

		if ( is_wp_error( $content ) ) {
			return self::subject_unavailable_response();
		}

		return self::subject_state_response(
			BlockContentCanonicalizer::bytes( $content ),
			(string) ( $row['subject_scope'] ?? 'template-part' )
		);
	}

	private static function subject_ref( string $subject_name, string $prefix ): string {
		if ( ! str_starts_with( $subject_name, $prefix ) ) {
			return '';
		}

		return trim( substr( $subject_name, strlen( $prefix ) ) );
	}

	private static function subject_state_response( string $canonical_bytes, string $scope ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'subject_canonical_b64' => KeyManager::b64url( $canonical_bytes ),
				'subject_digest'        => hash( 'sha256', $canonical_bytes ),
				'scope'                 => $scope,
			],
			200
		);
	}

	private static function subject_unavailable_response(): \WP_REST_Response {
		return new \WP_REST_Response( [ 'error' => 'subject_unavailable' ], 409 );
	}

	public function get_verification( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (string) $request->get_param( 'id' );
		$row = Repository::find( $id );

		if ( null === $row ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}

		$subject_response = $this->build_subject_state_response( $row );
		$subject_error    = null;
		$subject_bytes    = null;

		if ( $subject_response->get_status() < 200 || $subject_response->get_status() >= 300 ) {
			$subject_data  = $subject_response->get_data();
			$subject_error = is_array( $subject_data ) ? (string) ( $subject_data['error'] ?? 'subject_unavailable' ) : 'subject_unavailable';
		} else {
			$subject_data  = $subject_response->get_data();
			$subject_b64   = is_array( $subject_data ) ? (string) ( $subject_data['subject_canonical_b64'] ?? '' ) : '';
			$subject_bytes = '' !== $subject_b64 ? self::b64url_decode( $subject_b64 ) : null;
		}

		$reverted_by   = Repository::find_by_reverts( $id );
		$superseded_by = Repository::find_by_supersedes( $id );
		$outcomes      = Verifier::evaluate(
			(string) $row['statement_bytes'],
			self::b64url_decode( (string) $row['signature_b64'] ),
			KeyManager::jwks(),
			$subject_bytes,
			isset( $reverted_by['attestation_id'] ) ? (string) $reverted_by['attestation_id'] : null,
			isset( $superseded_by['attestation_id'] ) ? (string) $superseded_by['attestation_id'] : null
		);

		if ( null !== $subject_error ) {
			$outcomes[] = 'live_subject_unavailable';
		}

		return new \WP_REST_Response(
			[
				'attestationId'             => $id,
				'outcomes'                  => $outcomes,
				'subjectError'              => $subject_error,
				'revertedByAttestationId'   => $reverted_by['attestation_id'] ?? null,
				'supersededByAttestationId' => $superseded_by['attestation_id'] ?? null,
			],
			200
		);
	}

	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode(
			strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ),
			true
		);

		return false === $decoded ? '' : $decoded;
	}
}
