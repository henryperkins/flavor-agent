<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Activity\Repository as ActivityRepository;
use FlavorAgent\Attestation\AttestationService;
use FlavorAgent\Attestation\RecordResult;
use FlavorAgent\Attestation\Repository as AttestationRepository;

/**
 * The governed undo core: run the surface executor against live state, then
 * write the terminal activity status from what the executor actually did.
 *
 * Both undo entry points -- the `flavor-agent/undo-activity` ability and
 * `POST /flavor-agent/v1/activity/{id}/undo` -- dispatch here, so on any
 * surface that owns a server-side subject a verified revert is the only way a
 * row reaches `undo.status = "undone"`. Callers keep their own pre-checks and
 * response shaping; the verify-then-write step lives here once so the two
 * paths cannot drift apart again.
 */
final class UndoCoordinator {

	public const RESULT_UNDONE          = 'undone';
	public const RESULT_ALREADY_UNDONE  = 'already_undone';
	public const RESULT_FAILED          = 'failed';
	public const RESULT_CLIENT_REPORTED = 'client_reported';

	/** Terminal status established by reading -- and where needed writing -- live state. */
	public const VERIFICATION_SERVER = \FlavorAgent\Activity\Serializer::UNDO_VERIFICATION_SERVER;

	/** Terminal status is the editor's report; no server-side subject exists to verify it against. */
	public const VERIFICATION_CLIENT_REPORTED = \FlavorAgent\Activity\Serializer::UNDO_VERIFICATION_CLIENT_REPORTED;

	/** The row carries no snapshot this executor can compare live state against. */
	public const ERROR_SNAPSHOT_UNSUPPORTED = 'flavor_agent_undo_snapshot_unsupported';

	public const ERROR_DRIFT = 'flavor_agent_undo_drift';

	/**
	 * Pending, rejected, expired, and approval-failed external applies never
	 * executed, so there is nothing to revert. Callers must reject these before
	 * dispatching to an executor -- a pending row's snapshots describe a change
	 * the site never received, and undoing it would be an unreviewed mutation.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function is_non_executed_apply_entry( array $entry ): bool {
		$execution_result = (string) ( $entry['executionResult'] ?? '' );

		if ( in_array( $execution_result, [ 'pending', 'rejected', 'expired' ], true ) ) {
			return true;
		}

		return 'failed' === $execution_result
			&& '' === (string) ( $entry['apply']['executedAt'] ?? '' );
	}

	/**
	 * Resolve the row an undo targets: lookup plus pending-apply expiry.
	 *
	 * Shared by both undo entry points so lookup semantics cannot drift.
	 *
	 * @return array<string, mixed>|\WP_Error The hydrated (possibly expired) entry.
	 */
	public static function resolve_entry_for_undo( string $activity_id ): array|\WP_Error {
		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		return ActivityRepository::maybe_expire_pending_apply( $entry );
	}

	/**
	 * The ordered gate sequence both undo entry points run before any
	 * executor dispatch: the never-executed guard, the available-only
	 * transition check, and the ordered-undo gate.
	 *
	 * Living here once is the point -- the REST route and the ability used to
	 * carry their own copies, which is exactly the drift that let the two undo
	 * paths diverge in the first place. The single intentional difference
	 * between the callers is idempotency on an already-undone row: the ability
	 * reports it as success to external agents, the REST route rejects the
	 * re-transition, so that one choice is the flag.
	 *
	 * @param array<string, mixed> $entry Entry from resolve_entry_for_undo().
	 * @return array{alreadyUndone: bool}|\WP_Error
	 */
	public static function gate_undoable( string $activity_id, array $entry, bool $treat_undone_as_already_undone ): array|\WP_Error {
		if ( self::is_non_executed_apply_entry( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_undoable',
				'Pending, rejected, expired, and approval-failed external applies never executed and cannot be undone.',
				[ 'status' => 409 ]
			);
		}

		$undo_status = (string) ( $entry['undo']['status'] ?? '' );

		if ( $treat_undone_as_already_undone && 'undone' === $undo_status ) {
			return [ 'alreadyUndone' => true ];
		}

		if ( 'available' !== $undo_status ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_undo_transition',
				'Flavor Agent only allows undo status changes from the available state.',
				[ 'status' => 409 ]
			);
		}

		if ( ! ActivityRepository::can_perform_ordered_undo( $activity_id ) ) {
			return new \WP_Error(
				'flavor_agent_activity_undo_blocked',
				'Undo blocked by newer AI actions.',
				[ 'status' => 409 ]
			);
		}

		return [ 'alreadyUndone' => false ];
	}

	/**
	 * Revert the live subject, then record the outcome that revert produced.
	 *
	 * The caller is responsible for the gates that precede this -- run
	 * resolve_entry_for_undo() and gate_undoable() first.
	 *
	 * @param string                              $activity_id Row being undone.
	 * @param array<string, mixed>                $entry       Hydrated activity entry.
	 * @param class-string<ExternalApplyExecutor> $executor    Executor for the row's surface.
	 * @return array{entry: array<string, mixed>, result: string, error: string|null}|\WP_Error
	 */
	public static function run_verified_undo( string $activity_id, array $entry, string $executor ): array|\WP_Error {
		/*
		 * Authorize the target before the executor touches it. Row-level
		 * permission checks authorize `document`, the executor writes
		 * `target`, and both are caller-supplied -- so on post-blocks, whose
		 * capability is per-object, a row scoped to a post the caller may edit
		 * could otherwise drive a write to one they may not.
		 */
		$authorized = $executor::authorize_target( $entry );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$result = $executor::undo( $entry );

		if ( is_wp_error( $result ) ) {
			if ( self::ERROR_DRIFT !== $result->get_error_code() ) {
				// Passed through unchanged. Includes ERROR_SNAPSHOT_UNSUPPORTED,
				// which callers inspect to decide what an unverifiable row may do.
				return $result;
			}

			$failed = ActivityRepository::update_undo_status(
				$activity_id,
				'failed',
				$result->get_error_message(),
				[ 'verification' => self::VERIFICATION_SERVER ]
			);

			if ( is_wp_error( $failed ) ) {
				// The drift outcome could not be persisted. Surface the
				// storage failure rather than a normal `failed` result --
				// otherwise the caller reports drift while the audit row
				// silently stays `available` with no verification record.
				return $failed;
			}

			return [
				'entry'  => $failed,
				'result' => self::RESULT_FAILED,
				'error'  => $result->get_error_message(),
			];
		}

		$metadata                 = self::revert_attestation_metadata( $activity_id, $entry, $result );
		$metadata['verification'] = self::VERIFICATION_SERVER;

		// The subject is already reverted. update_undo_status() re-checks the
		// ordered-undo rule at the write boundary, and a newer activity on the
		// same entity landing since the caller's gate would reject this write --
		// leaving the row `available` while the change is gone. Refusing to
		// record a revert cannot un-perform it, so the ordered rule governs
		// whether an undo may start, not whether a completed one is recorded.
		$updated = ActivityRepository::update_undo_status(
			$activity_id,
			'undone',
			null,
			$metadata,
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return [
			'entry'  => $updated,
			'result' => self::RESULT_ALREADY_UNDONE === (string) ( $result['result'] ?? '' )
				? self::RESULT_ALREADY_UNDONE
				: self::RESULT_UNDONE,
			'error'  => null,
		];
	}

	/**
	 * Ring III revert attestation for the undo that just succeeded.
	 *
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $result Executor return value.
	 * @return array<string, mixed> Metadata merged into the undo state.
	 */
	private static function revert_attestation_metadata( string $activity_id, array $entry, array $result ): array {
		$surface = (string) ( $entry['surface'] ?? '' );

		if ( ! AttestationService::surface_eligible( $surface ) ) {
			return [];
		}

		$prior = AttestationRepository::find_by_related_activity( $activity_id );

		if ( null === $prior ) {
			return [ 'attestationStatus' => 'not_applicable' ];
		}

		try {
			$target              = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
			$persisted_after     = is_array( $result['after'] ?? null )
				? $result['after']
				: ( is_array( $entry['before'] ?? null ) ? $entry['before'] : [] );
			$attestation_context = [
				'surface'            => $surface,
				'operations'         => [],
				'before'             => $entry['after'] ?? [],
				'after'              => $persisted_after,
				'freshnessSignature' => '',
				'actorRole'          => self::actor_role_for_undo(),
				'requestedAt'        => '',
				'decidedAt'          => gmdate( 'c' ),
				'relatedActivityId'  => $activity_id,
			];

			if ( in_array( $surface, [ 'global-styles', 'style-book' ], true ) ) {
				$attestation_context['globalStylesId'] = (string) ( $target['globalStylesId'] ?? '' );
				$attestation_context['blockName']      = (string) ( $target['blockName'] ?? '' );
			} else {
				$attestation_context['templateRef'] = 'template-part' === $surface
					? (string) ( $target['templatePartRef'] ?? $target['templatePartId'] ?? '' )
					: (string) ( $target['templateRef'] ?? '' );
			}

			$attestation_result = AttestationService::record_revert(
				(string) $prior['attestation_id'],
				$attestation_context
			);

			return [
				'attestationStatus'    => $attestation_result->status(),
				'attestationErrorCode' => $attestation_result->error_code(),
			];
		} catch ( \Throwable $e ) {
			AttestationService::record_failure(
				$e,
				[
					'operation'            => 'revert',
					'activityId'           => $activity_id,
					'errorCode'            => 'unexpected_failure',
					'revertsAttestationId' => (string) $prior['attestation_id'],
				]
			);

			return [
				'attestationStatus'    => RecordResult::STATUS_FAILED,
				'attestationErrorCode' => 'unexpected_failure',
			];
		}
	}

	private static function actor_role_for_undo(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user  = get_userdata( $user_id );
		$roles = is_object( $user ) && is_array( $user->roles ?? null ) ? $user->roles : [];

		return isset( $roles[0] ) ? sanitize_key( (string) $roles[0] ) : '';
	}

	private function __construct() {}
}
