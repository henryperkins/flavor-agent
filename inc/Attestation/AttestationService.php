<?php

declare(strict_types=1);

namespace FlavorAgent\Attestation;

/**
 * Records self-signed site-key attestations for Flavor Agent's owned
 * external style-apply lane only.
 *
 * Self-signed site-key attestation; the live-match read is site-served.
 */
final class AttestationService {

	public const GOVERNANCE_CLAIM            = 'governed-change';
	public const GOVERNANCE_LANE             = 'external-style-apply-v1';
	public const GOVERNANCE_APPROVAL_SURFACE = 'settings-ai-activity';
	public const GOVERNANCE_EXECUTOR         = 'bounded-server-style-apply';

	private const ELIGIBLE_SURFACES  = [ 'global-styles', 'style-book' ];
	private const ELIGIBLE_DECISIONS = [ 'approve', 'revert' ];

	/**
	 * Record a signed attestation for the approved external style-apply lane.
	 * Returns the attestation id, or null when no signing key is configured.
	 *
	 * @param array<string, mixed> $ctx
	 */
	public static function record_apply( array $ctx ): ?string {
		if ( ! KeyManager::configured() ) {
			return null;
		}

		self::assert_owned_lane_context( $ctx );

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
		$decision   = (string) ( $ctx['decision'] ?? 'approve' );
		$supersedes = isset( $ctx['supersedesAttestationId'] ) ? trim( (string) $ctx['supersedesAttestationId'] ) : '';

		if ( '' === $supersedes && 'revert' !== $decision ) {
			$prior = Repository::find_latest_by_subject( $subject );

			if ( is_array( $prior ) ) {
				$supersedes = trim( (string) ( $prior['attestation_id'] ?? '' ) );
			}
		}

		$attestation_id = 'att_' . bin2hex( random_bytes( 16 ) );
		$statement      = StatementBuilder::build(
			[
				'attestationId'           => $attestation_id,
				'surface'                 => $surface,
				'scope'                   => $scope,
				'subjectName'             => $subject,
				'governanceClaim'         => self::GOVERNANCE_CLAIM,
				'governanceLane'          => self::GOVERNANCE_LANE,
				'approvalSurface'         => self::GOVERNANCE_APPROVAL_SURFACE,
				'executor'                => self::GOVERNANCE_EXECUTOR,
				'operations'              => is_array( $ctx['operations'] ?? null ) ? $ctx['operations'] : [],
				'beforeDigest'            => $before_dig,
				'afterDigest'             => $after_dig,
				'freshnessSignature'      => (string) ( $ctx['freshnessSignature'] ?? '' ),
				'actorRole'               => (string) ( $ctx['actorRole'] ?? '' ),
				'proposerVia'             => 'mcp/flavor-agent',
				'decision'                => $decision,
				'requestedAt'             => (string) ( $ctx['requestedAt'] ?? '' ),
				'decidedAt'               => (string) ( $ctx['decidedAt'] ?? '' ),
				'siteUrl'                 => (string) ( function_exists( 'home_url' ) ? home_url() : '' ),
				'keyId'                   => (string) KeyManager::key_id(),
				'relatedActivityId'       => isset( $ctx['relatedActivityId'] ) ? (string) $ctx['relatedActivityId'] : null,
				'revertsAttestationId'    => isset( $ctx['revertsAttestationId'] ) ? (string) $ctx['revertsAttestationId'] : null,
				'supersedesAttestationId' => '' !== $supersedes ? $supersedes : null,
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
				'supersedes_attestation_id' => '' !== $supersedes ? $supersedes : null,
				'related_activity_id'       => isset( $ctx['relatedActivityId'] ) ? (string) $ctx['relatedActivityId'] : null,
			]
		);

		return $ok ? $attestation_id : null;
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	public static function record_revert( string $prior_attestation_id, array $ctx ): ?string {
		$ctx['revertsAttestationId'] = $prior_attestation_id;
		$ctx['decision']             = 'revert';

		return self::record_apply( $ctx );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function record_failure( \Throwable $error, array $context = [] ): void {
		$event = [
			'operation'      => (string) ( $context['operation'] ?? 'record' ),
			'activityId'     => (string) ( $context['activityId'] ?? '' ),
			'exceptionClass' => get_class( $error ),
			'message'        => $error->getMessage(),
		];

		if ( isset( $context['attestationId'] ) ) {
			$event['attestationId'] = (string) $context['attestationId'];
		}

		if ( isset( $context['revertsAttestationId'] ) ) {
			$event['revertsAttestationId'] = (string) $context['revertsAttestationId'];
		}

		if ( function_exists( 'do_action' ) ) {
			try {
				\do_action( 'flavor_agent_attestation_record_failed', $event, $error );
			} catch ( \Throwable ) {
				// Diagnostic observers must not change apply or undo behavior.
			}
		}

		if ( ! function_exists( 'error_log' ) || ! self::should_log_failure( $event, $error ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Attestation failures are best-effort and otherwise invisible after a governed apply succeeds.
		\error_log(
			sprintf(
				'[flavor-agent] Attestation recording failed during %s for activity %s: %s - %s',
				$event['operation'],
				'' !== $event['activityId'] ? $event['activityId'] : '(none)',
				$event['exceptionClass'],
				$event['message']
			)
		);
	}

	/**
	 * @param array<string, string> $event
	 */
	private static function should_log_failure( array $event, \Throwable $error ): bool {
		if ( ! function_exists( 'apply_filters' ) ) {
			return true;
		}

		try {
			return (bool) \apply_filters(
				'flavor_agent_attestation_failure_logging_enabled',
				true,
				$event,
				$error
			);
		} catch ( \Throwable ) {
			return true;
		}
	}

	/**
	 * @param array<string, mixed> $ctx
	 */
	private static function assert_owned_lane_context( array $ctx ): void {
		$surface           = trim( (string) ( $ctx['surface'] ?? '' ) );
		$global_styles_id  = trim( (string) ( $ctx['globalStylesId'] ?? '' ) );
		$related_activity  = trim( (string) ( $ctx['relatedActivityId'] ?? '' ) );
		$decision          = trim( (string) ( $ctx['decision'] ?? 'approve' ) );
		$style_book_block  = trim( (string) ( $ctx['blockName'] ?? '' ) );
		$before_userconfig = $ctx['before']['userConfig'] ?? null;
		$after_userconfig  = $ctx['after']['userConfig'] ?? null;

		if ( ! in_array( $surface, self::ELIGIBLE_SURFACES, true ) ) {
			throw new \InvalidArgumentException( 'Flavor Agent only attests the external Global Styles / Style Book apply lane.' );
		}

		if ( '' === $global_styles_id ) {
			throw new \InvalidArgumentException( 'Flavor Agent attestations require a Global Styles entity id.' );
		}

		if ( 'style-book' === $surface && '' === $style_book_block ) {
			throw new \InvalidArgumentException( 'Style Book attestations require a block name.' );
		}

		if ( '' === $related_activity ) {
			throw new \InvalidArgumentException( 'Flavor Agent attestations require the related activity id from the governed external apply lane.' );
		}

		if ( ! in_array( $decision, self::ELIGIBLE_DECISIONS, true ) ) {
			throw new \InvalidArgumentException( 'Flavor Agent attestations only record approve or revert decisions.' );
		}

		if ( ! is_array( $before_userconfig ) || ! is_array( $after_userconfig ) ) {
			throw new \InvalidArgumentException( 'Flavor Agent attestations require canonical before/after userConfig arrays.' );
		}
	}

	private function __construct() {}
}
