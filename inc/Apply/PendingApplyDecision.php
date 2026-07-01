<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Activity\Repository as ActivityRepository;

/**
 * Admin approval service for pending external applies. The human gate:
 * re-checks freshness against the live entity, executes through the style
 * executor on approve, and persists the one-row transition either way.
 */
final class PendingApplyDecision {

	/**
	 * @return array<string, mixed>|\WP_Error The transitioned activity entry.
	 */
	public static function decide( string $activity_id, string $decision, string $note = '' ): array|\WP_Error {
		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return new \WP_Error(
				'flavor_agent_activity_not_found',
				'Flavor Agent could not find that activity entry.',
				[ 'status' => 404 ]
			);
		}

		$entry  = ActivityRepository::maybe_expire_pending_apply( $entry );
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

		$decided_by      = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$decided_by_name = self::actor_display_name( $decided_by );
		$decided_at      = gmdate( 'c' );
		$note            = sanitize_textarea_field( $note );

		if ( 'reject' === $decision ) {
			return ActivityRepository::transition_external_apply(
				$activity_id,
				[
					'applyStatus'   => 'rejected',
					'decidedBy'     => $decided_by,
					'decidedByName' => $decided_by_name,
					'decidedAt'     => $decided_at,
					'decisionNote'  => $note,
				]
			);
		}

		$surface          = (string) ( $entry['surface'] ?? '' );
		$target           = is_array( $entry['target'] ?? null ) ? $entry['target'] : [];
		$global_styles_id = (string) ( $target['globalStylesId'] ?? '' );
		$block_name       = (string) ( $target['blockName'] ?? '' );
		$signatures       = is_array( $apply['signatures'] ?? null ) ? $apply['signatures'] : [];
		$baseline         = (string) ( $signatures['baselineConfigHash'] ?? $signatures['baselineContentHash'] ?? '' );

		$executor = ExternalApplyExecutorRegistry::for_surface( $surface );

		if ( null === $executor ) {
			return ActivityRepository::transition_external_apply(
				$activity_id,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedByName'  => $decided_by_name,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => 'flavor_agent_apply_surface_unsupported',
					'failureMessage' => 'No external-apply executor is registered for this surface.',
				]
			);
		}

		// Second freshness check: the live baseline must still match the
		// baseline recorded at request time. Drift fails closed.
		$live_baseline = $executor::resolve_baseline( $entry );
		$stale_reason  = '';
		$failure_code  = 'flavor_agent_apply_stale';

		if ( is_wp_error( $live_baseline ) ) {
			$stale_reason = $live_baseline->get_error_message();
			$failure_code = 'flavor_agent_apply_resolve_failed';
		} elseif ( '' === $baseline ) {
			$stale_reason = 'The baseline configuration hash is missing from this external apply request.';
		} elseif ( ! hash_equals( $live_baseline, $baseline ) ) {
			$stale_reason = 'The target entity changed after this apply was requested.';
		}

		if ( '' !== $stale_reason ) {
			return ActivityRepository::transition_external_apply(
				$activity_id,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedByName'  => $decided_by_name,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => $failure_code,
					'failureMessage' => $stale_reason,
				]
			);
		}

		$result = $executor::execute( $entry );

		if ( is_wp_error( $result ) ) {
			return ActivityRepository::transition_external_apply(
				$activity_id,
				[
					'applyStatus'    => 'failed',
					'decidedBy'      => $decided_by,
					'decidedByName'  => $decided_by_name,
					'decidedAt'      => $decided_at,
					'decisionNote'   => $note,
					'failureCode'    => (string) $result->get_error_code(),
					'failureMessage' => (string) $result->get_error_message(),
				]
			);
		}

		// Attestation is hard-bounded to the external-style-apply-v1 lane. Only the
		// style surfaces may reach AttestationService; template-part (and any future
		// surface) applies record no attestation.
		if ( in_array( $surface, [ 'global-styles', 'style-book' ], true ) ) {
			try {
				\FlavorAgent\Attestation\AttestationService::record_apply(
					[
						'surface'            => $surface,
						'globalStylesId'     => $global_styles_id,
						'blockName'          => $block_name,
						'operations'         => is_array( $result['after']['operations'] ?? null ) ? $result['after']['operations'] : [],
						'before'             => $result['before'],
						'after'              => $result['after'],
						'freshnessSignature' => $baseline,
						'actorRole'          => self::actor_role( $decided_by ),
						'requestedAt'        => (string) ( $apply['requestedAt'] ?? '' ),
						'decidedAt'          => $decided_at,
						'relatedActivityId'  => $activity_id,
					]
				);
			} catch ( \Throwable $e ) {
				\FlavorAgent\Attestation\AttestationService::record_failure(
					$e,
					[
						'operation'  => 'apply',
						'activityId' => $activity_id,
					]
				);
			}
		}

		return ActivityRepository::transition_external_apply(
			$activity_id,
			[
				'applyStatus'   => 'available',
				'decidedBy'     => $decided_by,
				'decidedByName' => $decided_by_name,
				'decidedAt'     => $decided_at,
				'decisionNote'  => $note,
				'executedAt'    => gmdate( 'c' ),
				'before'        => $result['before'],
				'after'         => $result['after'],
				'target'        => $result['target'],
			]
		);
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
