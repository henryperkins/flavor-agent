<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Records self-signed site-key attestations for governed applies.
 *
 * Self-signed site-key attestation; the live-match read is site-served.
 */
final class AttestationService {

	/**
	 * Record a signed attestation for an approved apply. Returns the attestation
	 * id, or null when no signing key is configured.
	 *
	 * @param array<string, mixed> $ctx
	 */
	public static function record_apply( array $ctx ): ?string {
		if ( ! KeyManager::configured() ) {
			return null;
		}

		$surface    = (string) $ctx['surface'];
		$block_name = (string) ( $ctx['blockName'] ?? '' );
		$scope      = ( 'style-book' === $surface && '' !== $block_name ) ? 'style-book-branch' : 'global-styles';
		$subject    = 'wp_global_styles:' . (string) $ctx['globalStylesId'];

		if ( 'style-book-branch' === $scope ) {
			$subject .= '#' . $block_name;
		}

		$before_cfg = is_array( $ctx['before']['userConfig'] ?? null ) ? $ctx['before']['userConfig'] : [];
		$after_cfg  = is_array( $ctx['after']['userConfig'] ?? null ) ? $ctx['after']['userConfig'] : [];
		$before_dig = Canonicalizer::subject_digest( $before_cfg, $scope, $block_name );
		$after_dig  = Canonicalizer::subject_digest( $after_cfg, $scope, $block_name );

		$attestation_id = 'att_' . bin2hex( random_bytes( 12 ) );
		$statement      = StatementBuilder::build(
			[
				'attestationId'           => $attestation_id,
				'surface'                 => $surface,
				'scope'                   => $scope,
				'subjectName'             => $subject,
				'operations'              => is_array( $ctx['operations'] ?? null ) ? $ctx['operations'] : [],
				'beforeDigest'            => $before_dig,
				'afterDigest'             => $after_dig,
				'freshnessSignature'      => (string) ( $ctx['freshnessSignature'] ?? '' ),
				'actorRole'               => (string) ( $ctx['actorRole'] ?? '' ),
				'proposerVia'             => 'mcp/flavor-agent',
				'decision'                => (string) ( $ctx['decision'] ?? 'approve' ),
				'requestedAt'             => (string) ( $ctx['requestedAt'] ?? '' ),
				'decidedAt'               => (string) ( $ctx['decidedAt'] ?? '' ),
				'siteUrl'                 => (string) ( function_exists( 'home_url' ) ? home_url() : '' ),
				'keyId'                   => (string) KeyManager::key_id(),
				'relatedActivityId'       => isset( $ctx['relatedActivityId'] ) ? (string) $ctx['relatedActivityId'] : null,
				'revertsAttestationId'    => isset( $ctx['revertsAttestationId'] ) ? (string) $ctx['revertsAttestationId'] : null,
				'supersedesAttestationId' => isset( $ctx['supersedesAttestationId'] ) ? (string) $ctx['supersedesAttestationId'] : null,
			]
		);
		$signed         = Signer::sign( $statement );

		if ( null === $signed ) {
			return null;
		}

		$ok = Repository::insert(
			[
				'attestation_id'            => $attestation_id,
				'surface'                   => $surface,
				'subject_name'              => $subject,
				'subject_scope'             => $scope,
				'after_digest'              => $after_dig,
				'statement_bytes'           => $signed['statement'],
				'signature_b64'             => KeyManager::b64url( $signed['signature'] ),
				'key_id'                    => $signed['keyId'],
				'reverts_attestation_id'    => isset( $ctx['revertsAttestationId'] ) ? (string) $ctx['revertsAttestationId'] : null,
				'supersedes_attestation_id' => isset( $ctx['supersedesAttestationId'] ) ? (string) $ctx['supersedesAttestationId'] : null,
				'related_activity_id'       => isset( $ctx['relatedActivityId'] ) ? (string) $ctx['relatedActivityId'] : null,
			]
		);

		return $ok ? $attestation_id : null;
	}

	private function __construct() {}
}
