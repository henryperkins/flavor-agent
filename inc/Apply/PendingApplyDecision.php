<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Activity\ActivityStorageContext;
use FlavorAgent\Attestation\AttestationService;
use FlavorAgent\Attestation\RecordResult;

/**
 * Admin approval service for pending external applies. The human gate:
 * re-checks freshness against the live entity, executes through the registered
 * surface executor on approve, and persists the one-row transition either way.
 */
final class PendingApplyDecision {

	/**
	 * @return array<string, mixed>|\WP_Error The transitioned activity entry.
	 */
	public static function decide( string $activity_id, string $decision, string $note = '' ): array|\WP_Error {
		return self::decide_with_claim( $activity_id, $decision, $note );
	}

	/**
	 * Terminalize one abandoned decision claim after an administrator has
	 * independently confirmed process quiescence and reconciled the live target.
	 * The exact observed owner is consumed once and is never restored to pending.
	 *
	 * @return array<string, mixed>|\WP_Error The transitioned activity entry.
	 */
	public static function recover_abandoned_claim( string $activity_id, string $observed_claim, string $note = '' ): array|\WP_Error {
		$storage_context = ActivityRepository::capture_storage_context();

		if ( is_wp_error( $storage_context ) ) {
			return $storage_context;
		}

		$authorized = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );

		if ( ! $storage_context->matches_current() ) {
			return self::recovery_required_error( $activity_id, 'recovery_authorization_context', $storage_context );
		}

		if ( ! $authorized ) {
			return new \WP_Error(
				'flavor_agent_apply_target_forbidden',
				'Only an administrator can recover an abandoned Flavor Agent apply decision.',
				[ 'status' => 403 ]
			);
		}

		try {
			$decided_by      = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$decided_by_name = self::actor_display_name( $decided_by );
			$note            = sanitize_textarea_field( $note );
		} catch ( \Throwable $error ) {
			self::record_unexpected_failure( $activity_id, 'recovery_finalization', $error, $storage_context );

			return self::recovery_required_error( $activity_id, 'recovery_finalization', $storage_context );
		}

		if ( ! $storage_context->matches_current() ) {
			return self::recovery_required_error( $activity_id, 'recovery_finalization_context', $storage_context );
		}

		return self::transition_before_execution(
			$activity_id,
			$observed_claim,
			[
				'applyStatus'    => 'failed',
				'decidedBy'      => $decided_by,
				'decidedByName'  => $decided_by_name,
				'decidedAt'      => gmdate( 'c' ),
				'decisionNote'   => $note,
				'failureCode'    => 'flavor_agent_apply_recovery_required',
				'failureMessage' => 'An administrator closed an abandoned apply decision after reconciling the live target.',
			],
			$storage_context,
			'recovery_finalization',
			true
		);
	}

	/**
	 * Atomically claim and execute one activity decision.
	 *
	 * @return array<string, mixed>|\WP_Error The transitioned activity entry.
	 */
	private static function decide_with_claim( string $activity_id, string $decision, string $note ): array|\WP_Error {
		$storage_context = ActivityRepository::capture_storage_context();

		if ( is_wp_error( $storage_context ) ) {
			return $storage_context;
		}

		$entry = ActivityRepository::find( $activity_id, $storage_context );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$entry  = ActivityRepository::maybe_expire_pending_apply( $entry, $storage_context );
		$apply  = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$status = (string) ( $apply['status'] ?? '' );

		if ( 'expired' === $status ) {
			return new \WP_Error(
				'flavor_agent_apply_expired',
				'This external apply request expired before a decision was recorded.',
				[ 'status' => 410 ]
			);
		}

		if ( 'pending' !== $status ) {
			return new \WP_Error(
				'flavor_agent_apply_not_pending',
				'Only pending external applies accept decisions.',
				[ 'status' => 409 ]
			);
		}

		if ( ! in_array( $decision, [ 'approve', 'reject' ], true ) ) {
			return new \WP_Error(
				'flavor_agent_apply_invalid_decision',
				'External apply decisions must be approve or reject.',
				[ 'status' => 400 ]
			);
		}

		$claim = ActivityRepository::claim_external_apply_decision( $activity_id, $storage_context );

		if ( is_wp_error( $claim ) ) {
			return $claim;
		}

		$decided_by      = 0;
		$decided_by_name = '';
		$decided_at      = gmdate( 'c' );
		$raw_note        = $note;
		$note            = '';

		try {
			$decided_by      = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$decided_by_name = self::actor_display_name( $decided_by );
			$note            = sanitize_textarea_field( $raw_note );
		} catch ( \Throwable $error ) {
			return self::fail_unexpected_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				'decision_metadata',
				$error,
				$storage_context
			);
		}

		if ( ! $storage_context->matches_current() ) {
			return self::fail_context_changed_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				$storage_context
			);
		}

		if ( 'reject' === $decision ) {
			return self::transition_before_execution(
				$activity_id,
				$claim,
				[
					'applyStatus'   => 'rejected',
					'decidedBy'     => $decided_by,
					'decidedByName' => $decided_by_name,
					'decidedAt'     => $decided_at,
					'decisionNote'  => $note,
				],
				$storage_context
			);
		}

		$surface    = (string) ( $entry['surface'] ?? '' );
		$signatures = is_array( $apply['signatures'] ?? null ) ? $apply['signatures'] : [];
		$baseline   = (string) ( $signatures['baselineConfigHash'] ?? $signatures['baselineContentHash'] ?? '' );

		try {
			$executor = ExternalApplyExecutorRegistry::for_surface( $surface );
		} catch ( \Throwable $error ) {
			return self::fail_unexpected_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				'registry',
				$error,
				$storage_context
			);
		}

		if ( null === $executor ) {
			return self::transition_before_execution(
				$activity_id,
				$claim,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedByName'  => $decided_by_name,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => 'flavor_agent_apply_surface_unsupported',
					'failureMessage' => 'No external-apply executor is registered for this surface.',
				],
				$storage_context
			);
		}

		try {
			$authorized = $executor::authorize_target( $entry );
		} catch ( \Throwable $error ) {
			return self::fail_unexpected_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				'authorization',
				$error,
				$storage_context
			);
		}

		if ( ! $storage_context->matches_current() ) {
			return self::fail_context_changed_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				$storage_context
			);
		}

		if ( is_wp_error( $authorized ) ) {
			return self::transition_before_execution(
				$activity_id,
				$claim,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedByName'  => $decided_by_name,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => (string) $authorized->get_error_code(),
					'failureMessage' => (string) $authorized->get_error_message(),
				],
				$storage_context
			);
		}

		// Second freshness check: the live baseline must still match the
		// baseline recorded at request time. Drift fails closed.
		try {
			$live_baseline = $executor::resolve_baseline( $entry );
		} catch ( \Throwable $error ) {
			return self::fail_unexpected_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				'baseline',
				$error,
				$storage_context
			);
		}

		if ( ! $storage_context->matches_current() ) {
			return self::fail_context_changed_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				$storage_context
			);
		}

		if ( ! is_string( $live_baseline ) && ! is_wp_error( $live_baseline ) ) {
			return self::fail_unexpected_before_execution(
				$activity_id,
				$claim,
				$decided_by,
				$decided_by_name,
				$decided_at,
				$note,
				'baseline',
				new \UnexpectedValueException( 'The executor returned an invalid baseline result.' ),
				$storage_context
			);
		}

		$stale_reason = '';
		$failure_code = 'flavor_agent_apply_stale';

		if ( is_wp_error( $live_baseline ) ) {
			$stale_reason = $live_baseline->get_error_message();
			$failure_code = 'flavor_agent_apply_resolve_failed';
		} elseif ( '' === $baseline ) {
			$stale_reason = 'The baseline configuration hash is missing from this external apply request.';
		} elseif ( ! hash_equals( $live_baseline, $baseline ) ) {
			$stale_reason = 'The target entity changed after this apply was requested.';
		}

		if ( '' !== $stale_reason ) {
			return self::transition_before_execution(
				$activity_id,
				$claim,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedByName'  => $decided_by_name,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => $failure_code,
					'failureMessage' => $stale_reason,
				],
				$storage_context
			);
		}

		try {
			$result = $executor::execute( $entry );
		} catch ( \Throwable $error ) {
			self::record_unexpected_failure( $activity_id, 'execute', $error, $storage_context );

			return self::recovery_required_error( $activity_id, 'execute', $storage_context );
		}

		if ( ! $storage_context->matches_current() ) {
			return self::recovery_required_error( $activity_id, 'site_context', $storage_context );
		}

		if ( is_wp_error( $result ) ) {
			if ( in_array( $result->get_error_code(), [ 'flavor_agent_apply_materialization_locked', 'flavor_agent_apply_lock_unavailable' ], true ) ) {
				try {
					$released = ActivityRepository::release_external_apply_decision_claim( $activity_id, $claim, $storage_context );
				} catch ( \Throwable $error ) {
					self::record_unexpected_failure( $activity_id, 'claim_release', $error, $storage_context );

					return self::recovery_required_error( $activity_id, 'claim_release', $storage_context );
				}

				if ( is_wp_error( $released ) ) {
					return self::recovery_required_error( $activity_id, 'claim_release', $storage_context );
				}

				return $result;
			}

			if ( 'flavor_agent_apply_recovery_required' === $result->get_error_code() ) {
				return $result;
			}

			return self::transition_after_execution(
				$activity_id,
				$claim,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedByName'  => $decided_by_name,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => (string) $result->get_error_code(),
					'failureMessage' => (string) $result->get_error_message(),
				],
				$storage_context
			);
		}

		if (
			! is_array( $result )
			|| ! is_array( $result['target'] ?? null )
			|| ! is_array( $result['before'] ?? null )
			|| ! is_array( $result['after'] ?? null )
		) {
			self::record_unexpected_failure(
				$activity_id,
				'execute',
				new \UnexpectedValueException( 'The executor returned an invalid apply result.' ),
				$storage_context
			);

			return self::recovery_required_error( $activity_id, 'execute', $storage_context );
		}

		$attestation_status     = null;
		$attestation_error_code = null;

		if ( AttestationService::surface_eligible( $surface ) ) {
			try {
				$result_target       = is_array( $result['target'] ?? null ) ? $result['target'] : [];
				$attestation_context = [
					'surface'            => $surface,
					'operations'         => is_array( $result['after']['operations'] ?? null ) ? $result['after']['operations'] : [],
					'before'             => $result['before'],
					'after'              => $result['after'],
					'freshnessSignature' => $baseline,
					'actorRole'          => self::actor_role( $decided_by ),
					'requestedAt'        => (string) ( $apply['requestedAt'] ?? '' ),
					'decidedAt'          => $decided_at,
					'relatedActivityId'  => $activity_id,
				];

				foreach ( self::apply_attestation_target_fields( $surface, $result_target ) as $key => $value ) {
					$attestation_context[ $key ] = $value;
				}

				$attestation_result     = AttestationService::record_apply( $attestation_context, $storage_context );
				$attestation_status     = $attestation_result->status();
				$attestation_error_code = $attestation_result->error_code();
			} catch ( \Throwable $e ) {
				$attestation_status     = RecordResult::STATUS_FAILED;
				$attestation_error_code = 'unexpected_failure';

				if ( $storage_context->matches_current() ) {
					AttestationService::record_failure(
						$e,
						[
							'operation'  => 'apply',
							'activityId' => $activity_id,
							'errorCode'  => $attestation_error_code,
						]
					);
				}
			}
		}

		if ( ! $storage_context->matches_current() ) {
			return self::recovery_required_error( $activity_id, 'attestation_context', $storage_context );
		}

		$changes = [
			'applyStatus'   => 'available',
			'decidedBy'     => $decided_by,
			'decidedByName' => $decided_by_name,
			'decidedAt'     => $decided_at,
			'decisionNote'  => $note,
			'executedAt'    => gmdate( 'c' ),
			'before'        => $result['before'],
			'after'         => $result['after'],
			'target'        => $result['target'],
		];

		if ( null !== $attestation_status ) {
			$changes['attestationStatus']    = $attestation_status;
			$changes['attestationErrorCode'] = $attestation_error_code;
		}

		return self::transition_after_execution(
			$activity_id,
			$claim,
			$changes,
			$storage_context
		);
	}

	/**
	 * An unexpected pre-write failure can safely consume the claim as failed.
	 * If that terminal activity update is itself uncertain, preserve the
	 * fail-closed operator-recovery contract instead of exposing the exception.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fail_unexpected_before_execution(
		string $activity_id,
		string $claim,
		int $decided_by,
		string $decided_by_name,
		string $decided_at,
		string $note,
		string $phase,
		\Throwable $error,
		ActivityStorageContext $storage_context
	): array|\WP_Error {
		self::record_unexpected_failure( $activity_id, $phase, $error, $storage_context );

		return self::transition_before_execution(
			$activity_id,
			$claim,
			[
				'applyStatus'    => 'failed',
				'decidedBy'      => $decided_by,
				'decidedByName'  => $decided_by_name,
				'decidedAt'      => $decided_at,
				'decisionNote'   => $note,
				'failureCode'    => 'flavor_agent_apply_unexpected_failure',
				'failureMessage' => 'Flavor Agent stopped before target execution because an unexpected internal failure occurred.',
			],
			$storage_context
		);
	}

	private static function fail_context_changed_before_execution(
		string $activity_id,
		string $claim,
		int $decided_by,
		string $decided_by_name,
		string $decided_at,
		string $note,
		ActivityStorageContext $storage_context
	): array|\WP_Error {
		return self::transition_before_execution(
			$activity_id,
			$claim,
			[
				'applyStatus'    => 'failed',
				'decidedBy'      => $decided_by,
				'decidedByName'  => $decided_by_name,
				'decidedAt'      => $decided_at,
				'decisionNote'   => $note,
				'failureCode'    => 'flavor_agent_apply_write_context_changed',
				'failureMessage' => 'Flavor Agent stopped before target execution because the site database context changed.',
			],
			$storage_context
		);
	}

	/**
	 * Consume a claim before the mutation boundary. Storage uncertainty still
	 * requires explicit operator reconciliation of the activity owner state.
	 *
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function transition_before_execution(
		string $activity_id,
		string $claim,
		array $changes,
		ActivityStorageContext $storage_context,
		string $failure_phase = 'pre_execution_finalization',
		bool $preserve_owner_conflict = false
	): array|\WP_Error {
		try {
			$transitioned = ActivityRepository::transition_claimed_external_apply( $activity_id, $changes, $claim, $storage_context );
		} catch ( \Throwable $transition_error ) {
			self::record_unexpected_failure( $activity_id, $failure_phase, $transition_error, $storage_context );

			return self::recovery_required_error( $activity_id, $failure_phase, $storage_context );
		}

		if ( is_wp_error( $transitioned ) ) {
			if ( $preserve_owner_conflict && 'flavor_agent_apply_invalid_transition' === $transitioned->get_error_code() ) {
				return $transitioned;
			}

			self::record_unexpected_failure(
				$activity_id,
				$failure_phase,
				new \RuntimeException( 'The pre-execution apply decision could not be terminalized: ' . (string) $transitioned->get_error_code() ),
				$storage_context
			);

			return self::recovery_required_error( $activity_id, $failure_phase, $storage_context );
		}

		return $transitioned;
	}

	/**
	 * Once execute has returned, any uncertain terminal activity write requires
	 * reconciliation because the target may already have changed.
	 *
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function transition_after_execution( string $activity_id, string $claim, array $changes, ActivityStorageContext $storage_context ): array|\WP_Error {
		try {
			$transitioned = ActivityRepository::transition_claimed_external_apply( $activity_id, $changes, $claim, $storage_context );
		} catch ( \Throwable $error ) {
			if ( ! $storage_context->matches_current() ) {
				return self::recovery_required_error( $activity_id, 'finalization_context', $storage_context );
			}

			self::record_unexpected_failure( $activity_id, 'finalization', $error, $storage_context );

			return self::recovery_required_error( $activity_id, 'finalization', $storage_context );
		}

		if ( ! $storage_context->matches_current() ) {
			return self::recovery_required_error( $activity_id, 'finalization_context', $storage_context );
		}

		if ( is_wp_error( $transitioned ) ) {
			self::record_unexpected_failure(
				$activity_id,
				'finalization',
				new \RuntimeException( 'The executed apply could not be terminalized: ' . (string) $transitioned->get_error_code() ),
				$storage_context
			);

			return self::recovery_required_error( $activity_id, 'finalization', $storage_context );
		}

		return $transitioned;
	}

	private static function record_unexpected_failure( string $activity_id, string $phase, \Throwable $error, ?ActivityStorageContext $storage_context = null ): void {
		if ( null !== $storage_context && ! $storage_context->matches_current() ) {
			return;
		}

		AttestationService::record_failure(
			$error,
			[
				'operation'  => 'apply_' . $phase,
				'activityId' => $activity_id,
				'errorCode'  => 'unexpected_failure',
			]
		);
	}

	private static function recovery_required_error( string $activity_id, string $phase, ?ActivityStorageContext $storage_context = null ): \WP_Error {
		$diagnostics = ActivityRepository::external_apply_decision_claim_diagnostics( $activity_id, $storage_context );
		$data        = [
			'status'           => 500,
			'recoveryRequired' => true,
			'activityId'       => $activity_id,
			'phase'            => $phase,
		];

		if ( isset( $diagnostics['processingSince'] ) ) {
			$data['processingSince'] = (string) $diagnostics['processingSince'];
		}

		return new \WP_Error(
			'flavor_agent_apply_recovery_required',
			'Flavor Agent could not prove a safe terminal apply state. Reconcile the target before recovering this activity.',
			$data
		);
	}

	/**
	 * @param array<string, mixed> $target
	 * @return array<string, string>
	 */
	private static function apply_attestation_target_fields( string $surface, array $target ): array {
		if ( in_array( $surface, [ 'global-styles', 'style-book' ], true ) ) {
			return [
				'globalStylesId' => (string) ( $target['globalStylesId'] ?? '' ),
				'blockName'      => (string) ( $target['blockName'] ?? '' ),
			];
		}

		return [
			'templateRef' => 'template-part' === $surface
				? (string) ( $target['templatePartRef'] ?? '' )
				: (string) ( $target['templateRef'] ?? '' ),
		];
	}

	private static function actor_role( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user  = get_userdata( $user_id );
		$roles = is_object( $user ) && is_array( $user->roles ?? null ) ? $user->roles : [];

		return isset( $roles[0] ) ? sanitize_key( (string) $roles[0] ) : '';
	}

	/**
	 * Durable, human-readable snapshot of the deciding user at decision time.
	 *
	 * The internal audit row's answer to "who approved this" — the FA-internal
	 * analogue of the lane-4 `approved_by` provenance field. Deliberately kept
	 * off the public attestation envelope, which stays role-only (PII-minimal).
	 */
	private static function actor_display_name( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user = get_userdata( $user_id );

		if ( ! is_object( $user ) ) {
			return '';
		}

		$display_name = isset( $user->display_name ) ? trim( (string) $user->display_name ) : '';

		if ( '' === $display_name ) {
			$display_name = isset( $user->user_login ) ? (string) $user->user_login : '';
		}

		return sanitize_text_field( $display_name );
	}
}
