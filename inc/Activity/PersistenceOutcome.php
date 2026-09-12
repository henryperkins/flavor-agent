<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Internal authority and normalization for save evidence. */
final class PersistenceOutcome {

	public const VERIFIER_VERSION = 'save-persistence-v1';
	public const SERVER_EVENTS    = [ 'save_confirmed', 'save_discarded', 'save_unverifiable' ];
	public const CLIENT_EVENTS    = [ 'save_attempted', 'save_failed' ];
	public const APPLY_TYPES      = [ 'apply_suggestion', 'apply_block_structural_suggestion', 'apply_template_suggestion', 'apply_template_part_suggestion', 'apply_global_styles_suggestion', 'apply_style_book_suggestion' ];

	private static ?array $authoring_apply = null;

	public static function is_authoring(): bool {
		return null !== self::$authoring_apply;
	}

	public static function author_id(): ?int {
		return self::is_authoring() ? (int) ( self::$authoring_apply['userId'] ?? 0 ) : null;
	}

	public static function is_lifecycle_event( string $event ): bool {
		return in_array( $event, array_merge( self::SERVER_EVENTS, self::CLIENT_EVENTS ), true );
	}

	/** The caller cannot establish authority through fields in an entry. */
	public static function normalize_entry( array $entry ): array|\WP_Error {
		$outcome   = is_array( $entry['after']['outcome'] ?? null ) ? $entry['after']['outcome'] : [];
		$event     = (string) ( $outcome['event'] ?? '' );
		$is_server = in_array( $event, self::SERVER_EVENTS, true );
		if ( $is_server && ! self::is_authoring() ) {
			return new \WP_Error( 'flavor_agent_persistence_server_authorship_required', 'Only the server can record a persistence verdict.', [ 'status' => 403 ] );
		}
		$apply_id      = (string) ( $entry['linkedApplyActivityId'] ?? '' );
		$occurrence_id = (string) ( $entry['saveOccurrenceId'] ?? '' );
		if ( '' === $apply_id || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $occurrence_id ) ) {
			return self::invalid( 'Save outcomes require an apply and a valid occurrence.' );
		}
		$apply = $is_server ? self::$authoring_apply : Repository::find( $apply_id );
		if ( ! is_array( $apply ) || ( $apply['id'] ?? '' ) !== $apply_id || 'editor-state' !== ( $apply['applyLane'] ?? '' ) || ! in_array( $apply['type'] ?? '', self::APPLY_TYPES, true ) ) {
			return self::invalid( 'The linked editor apply is unavailable.', 409 );
		}
		if ( ! $is_server && ( ! Permissions::can_access_entry( $apply ) || (int) ( $apply['userId'] ?? 0 ) !== (int) get_current_user_id() ) ) {
			return Permissions::forbidden_error();
		}
		$labels     = [
			'save_attempted'    => 'Save requested',
			'save_failed'       => 'Save request failed',
			'save_confirmed'    => 'Recommendation persisted',
			'save_discarded'    => 'Recommendation not present in saved content',
			'save_unverifiable' => 'Saved change could not be verified',
		];
		$normalized = [
			'event'            => $event,
			'visibility'       => 'diagnostic',
			'applyActivityId'  => $apply_id,
			'saveOccurrenceId' => $occurrence_id,
		];
		if ( ! $is_server && is_string( $outcome['observedAt'] ?? null ) && strlen( $outcome['observedAt'] ) <= 40 && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $outcome['observedAt'] ) && false !== strtotime( $outcome['observedAt'] ) ) {
			// Client observation time orders requests only; it never orders saved versions.
			$normalized['observedAt'] = $outcome['observedAt'];
		}
		if ( $is_server ) {
			// Only this internal branch retains comparison evidence. Client data
			// never supplies a sequence, identity, fingerprint, or saver identity.
			foreach ( [ 'saveSequence', 'origin', 'entity', 'contentFingerprint', 'verifierVersion', 'reason', 'captureReason', 'operations', 'snapshotId', 'saverUserId' ] as $key ) {
				$normalized[ $key ] = $outcome[ $key ] ?? null;
			}
		}
		return [
			...$entry,
			'id'                    => $is_server ? (string) $entry['id'] : 'save-event-' . hash( 'sha256', $apply_id . ':' . $occurrence_id . ':' . $event ),
			'type'                  => RecommendationOutcome::TYPE,
			'surface'               => $apply['surface'],
			'document'              => $apply['document'],
			'target'                => $apply['target'],
			'suggestion'            => $labels[ $event ],
			'suggestionKey'         => null,
			'applyLane'             => null,
			'linkedApplyActivityId' => $apply_id,
			'saveOccurrenceId'      => $occurrence_id,
			'before'                => [],
			'after'                 => [ 'outcome' => $normalized ],
			'request'               => [],
			'executionResult'       => 'diagnostic',
			'undo'                  => [ 'status' => 'not_applicable' ],
			'diagnostic'            => true,
		];
	}

	/** Write one immutable verdict per apply, occurrence, and verifier version. */
	public static function write_verdict( array $apply, array $snapshot, array $comparison ): array|\WP_Error {
		$stored = Repository::find( (string) ( $apply['id'] ?? '' ) );
		if ( ! is_array( $stored ) || 'editor-state' !== ( $stored['applyLane'] ?? null ) || ! in_array( $comparison['event'] ?? '', self::SERVER_EVENTS, true ) ) {
			return self::invalid( 'The persistence verdict has no eligible apply.' );
		}
		$occurrence_id = (string) ( $snapshot['saveOccurrenceId'] ?? '' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $occurrence_id ) || (int) ( $snapshot['saveSequence'] ?? 0 ) <= 0 || '' === (string) ( $snapshot['snapshotId'] ?? '' ) || PersistenceOccurrenceRepository::entity_key( $snapshot['entity'] ?? [] ) !== PersistenceOccurrenceRepository::entity_key( PersistenceOccurrenceRepository::entity_for_apply( $stored ) ) ) {
			return self::invalid( 'The persistence verdict requires a captured version of the same entity.' );
		}
		$id       = 'save-verdict-' . hash( 'sha256', $stored['id'] . ':' . $occurrence_id . ':' . self::VERIFIER_VERSION );
		$existing = Repository::find( $id );
		if ( is_array( $existing ) ) {
			return $existing;
		}
		$previous              = self::$authoring_apply;
		self::$authoring_apply = $stored;
		try {
			$result = Repository::create(
				[
					'id'                    => $id,
					'type'                  => RecommendationOutcome::TYPE,
					'surface'               => $stored['surface'],
					'document'              => $stored['document'],
					'linkedApplyActivityId' => $stored['id'],
					'saveOccurrenceId'      => $occurrence_id,
					'after'                 => [
						'outcome' => [
							...$comparison,
							'saveSequence'       => (int) ( $snapshot['saveSequence'] ?? 0 ),
							'origin'             => (string) ( $snapshot['origin'] ?? 'unobserved' ),
							'entity'             => $snapshot['entity'] ?? [],
							'contentFingerprint' => (string) ( $snapshot['contentFingerprint'] ?? '' ),
							'verifierVersion'    => self::VERIFIER_VERSION,
							'snapshotId'         => (string) ( $snapshot['snapshotId'] ?? '' ),
							'captureReason'      => (string) ( $snapshot['reason'] ?? 'saved' ),
							'saverUserId'        => (int) ( $snapshot['saverUserId'] ?? 0 ),
						],
					],
				]
			);
			// A concurrent worker may have inserted the same immutable tuple.
			return is_wp_error( $result ) ? ( Repository::find( $id ) ?? $result ) : $result;
		} finally {
			self::$authoring_apply = $previous;
		}
	}

	private static function invalid( string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'flavor_agent_persistence_invalid_outcome', $message, [ 'status' => $status ] );
	}
}
