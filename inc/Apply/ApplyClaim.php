<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

use FlavorAgent\Activity\Repository as ActivityRepository;

/**
 * Advisory, auto-expiring "being reviewed by X" claim for pending external
 * applies. A claim is a best-effort visibility hint — never a lock. It is
 * never read by PendingApplyDecision::decide and never gates a decision.
 * Single-execution is enforced solely by Repository::transition_external_apply's
 * write-boundary pending guard.
 */
final class ApplyClaim {

	public const TTL = 5 * MINUTE_IN_SECONDS;

	private static function key( string $activity_id ): string {
		// md5 is a non-cryptographic cache-key hash: activity_id is varchar(191)
		// and caller-supplied, so concatenating it raw could exceed WordPress's
		// transient-name length limit. The fixed 32-char digest stays well under.
		return 'flavor_agent_apply_claim_' . md5( $activity_id );
	}

	/**
	 * @return array{userId: int, claimedAt: string}|null
	 */
	public static function get( string $activity_id ): ?array {
		$value = get_transient( self::key( $activity_id ) );

		if ( ! is_array( $value ) ) {
			return null;
		}

		$user_id = (int) ( $value['userId'] ?? 0 );

		if ( $user_id <= 0 ) {
			return null;
		}

		return [
			'userId'    => $user_id,
			'claimedAt' => (string) ( $value['claimedAt'] ?? '' ),
		];
	}

	/**
	 * @return array{claim: array{userId: int, claimedAt: string}|null, entry: array<string, mixed>}|\WP_Error
	 */
	public static function claim( string $activity_id, int $user_id ): array|\WP_Error {
		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return self::not_found_error();
		}

		$entry  = ActivityRepository::maybe_expire_pending_apply( $entry );
		$apply  = is_array( $entry['apply'] ?? null ) ? $entry['apply'] : [];
		$status = (string) ( $apply['status'] ?? '' );

		if ( 'pending' !== $status ) {
			return [
				'claim' => null,
				'entry' => $entry,
			];
		}

		$existing = self::get( $activity_id );

		// Another user holds a live claim: report it, never steal. The write is
		// best-effort (no CAS), so a simultaneous claim can still win benignly.
		if ( is_array( $existing ) && $existing['userId'] !== $user_id ) {
			return [
				'claim' => $existing,
				'entry' => $entry,
			];
		}

		$claim = [
			'userId'    => $user_id,
			'claimedAt' => gmdate( 'c' ),
		];

		set_transient( self::key( $activity_id ), $claim, self::TTL );

		return [
			'claim' => $claim,
			'entry' => $entry,
		];
	}

	/**
	 * @return array{claim: array{userId: int, claimedAt: string}|null, entry: array<string, mixed>}|\WP_Error
	 */
	public static function release( string $activity_id, int $user_id ): array|\WP_Error {
		$entry = ActivityRepository::find( $activity_id );

		if ( ! is_array( $entry ) ) {
			return self::not_found_error();
		}

		$existing = self::get( $activity_id );

		if ( null === $existing || $existing['userId'] === $user_id ) {
			delete_transient( self::key( $activity_id ) );

			return [
				'claim' => null,
				'entry' => $entry,
			];
		}

		// Foreign live claim — release is not a steal vector.
		return [
			'claim' => $existing,
			'entry' => $entry,
		];
	}

	/**
	 * Unconditional delete, called only from transition_external_apply's
	 * committed-success path so a decided row never shows a stale claim.
	 */
	public static function clear( string $activity_id ): void {
		delete_transient( self::key( $activity_id ) );
	}

	private static function not_found_error(): \WP_Error {
		return new \WP_Error(
			'flavor_agent_activity_not_found',
			'Flavor Agent could not find that activity entry.',
			[ 'status' => 404 ]
		);
	}
}
