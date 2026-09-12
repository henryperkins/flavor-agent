<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Derives independent persistence, coverage, request and undo facts. */
final class PersistenceAssurance {

	private const LOOKUP_CHUNK_SIZE = 100;

	/** @param array<int,array<string,mixed>> $entries Authorized stored activity rows. */
	public static function enrich( array $entries ): array {
		return self::enrich_with_context( $entries )['entries'];
	}

	/** Use one evidence read for both the displayed fields and their metric cohorts. */
	private static function enrich_with_context( array $entries ): array {
		$context  = self::context( $entries );
		$resolved = [];
		foreach ( $context['applies'] as $id => $apply ) {
			$resolved[ $id ] = self::resolve( $apply, $context['evidence'][ $id ] ?? [] );
		}
		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id                = self::is_apply( $entry ) ? (string) ( $entry['id'] ?? '' ) : (string) ( $entry['linkedApplyActivityId'] ?? '' );
			$entries[ $index ] = array_merge( $entry, $resolved[ $id ] ?? self::resolve( $entry, [] ) );
		}
		return [
			'entries' => $entries,
			'context' => $context,
		];
	}

	/** The six persistence metrics share exactly the same resolver as activity UI. */
	public static function metrics( array $entries ): array {
		$resolved = self::enrich_with_context( $entries );
		$enriched = $resolved['entries'];
		$context  = $resolved['context'];
		$counts   = [
			'eligible'   => 0,
			'compared'   => 0,
			'conclusive' => 0,
			'confirmed'  => 0,
		];
		$seen     = [];
		foreach ( $enriched as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = self::is_apply( $entry ) ? (string) ( $entry['id'] ?? '' ) : (string) ( $entry['linkedApplyActivityId'] ?? '' );
			if ( '' === $id || isset( $seen[ $id ] ) || ! isset( $context['applies'][ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$coverage    = $entry['verificationCoverage'];
			foreach ( [ 'eligible', 'compared', 'conclusive' ] as $cohort ) {
				$counts[ $cohort ] += $coverage[ $cohort ] ? 1 : 0;
			}
			$counts['confirmed'] += 'save_confirmed' === $entry['persistenceVerdict']['state'] ? 1 : 0;
		}
		$attempts = [];
		foreach ( $context['evidence'] as $evidence ) {
			foreach ( $evidence as $entry ) {
				if ( 'save_attempted' === ( $entry['after']['outcome']['event'] ?? '' ) ) {
					$attempts[ $entry['saveOccurrenceId'] ] = true;
				}
			}
		}
		return [
			'saveAttemptedOccurrences' => count( $attempts ),
			'savePersistedRate'        => self::rate( $counts['confirmed'], $counts['conclusive'] ),
			'saveDiscardedRate'        => self::rate( $counts['conclusive'] - $counts['confirmed'], $counts['conclusive'] ),
			'saveUnverifiableRate'     => self::rate( $counts['compared'] - $counts['conclusive'], $counts['compared'] ),
			'verificationCoverageRate' => self::rate( $counts['compared'], $counts['eligible'] ),
			'unverifiedCoverageCount'  => $counts['eligible'] - $counts['compared'],
		];
	}

	/** Load only explicit links, independently of the caller's page/date filters. */
	private static function context( array $entries ): array {
		$applies = [];
		$links   = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = (string) ( $entry['id'] ?? '' );
			if ( '' !== $id && self::is_apply( $entry ) ) {
				$applies[ $id ] = $entry;
			}
			$link = (string) ( $entry['linkedApplyActivityId'] ?? '' );
			if ( '' !== $link && RecommendationOutcome::TYPE === ( $entry['type'] ?? '' ) ) {
				$links[ $link ][] = $entry;
			}
		}
		$missing = array_keys( array_diff_key( $links, $applies ) );
		foreach ( self::lookup( 'activity_id', $missing ) as $entry ) {
			$id = (string) ( $entry['id'] ?? '' );
			if ( ! self::is_apply( $entry ) || ! isset( $links[ $id ] ) ) {
				continue;
			}
			foreach ( $links[ $id ] as $linked ) {
				if ( self::same_owner_and_scope( $entry, $linked ) ) {
					$applies[ $id ] = $entry;
					break;
				}
			}
		}
		$evidence = [];
		foreach ( self::lookup( 'linked_apply_activity_id', array_keys( $applies ) ) as $entry ) {
			$link       = (string) ( $entry['linkedApplyActivityId'] ?? '' );
			$outcome    = is_array( $entry['after']['outcome'] ?? null ) ? $entry['after']['outcome'] : [];
			$occurrence = (string) ( $entry['saveOccurrenceId'] ?? '' );
			if ( ! isset( $applies[ $link ] ) || RecommendationOutcome::TYPE !== $entry['type'] || ! PersistenceOutcome::is_lifecycle_event( (string) ( $outcome['event'] ?? '' ) ) || '' === $occurrence || ! self::same_owner_and_scope( $applies[ $link ], $entry ) ) {
				continue;
			}
			if ( ( $outcome['applyActivityId'] ?? $link ) !== $link || ( $outcome['saveOccurrenceId'] ?? $occurrence ) !== $occurrence ) {
				continue;
			}
			$evidence[ $link ][] = $entry;
		}
		return [
			'applies'  => $applies,
			'evidence' => $evidence,
		];
	}

	/** No Repository::find() calls: hydration must never recurse into assurance. */
	private static function lookup( string $column, array $ids ): array {
		global $wpdb;
		$entries = [];
		if ( [] === $ids || ! isset( $wpdb ) ) {
			return $entries;
		}
		// These are the only two caller-independent identifiers this method accepts.
		if ( ! in_array( $column, [ 'activity_id', 'linked_apply_activity_id' ], true ) ) {
			return $entries;
		}
		foreach ( array_chunk( array_values( array_unique( $ids ) ), self::LOOKUP_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$sql          = "SELECT * FROM %i WHERE {$column} IN ({$placeholders}) ORDER BY id ASC";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed column allowlist; table and every link value are prepared. Linked evidence has no presentation-window limit.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, Repository::table_name(), ...$chunk ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				if ( ! in_array( (string) ( $row[ $column ] ?? '' ), $chunk, true ) ) {
					continue;
				}
				$entry                      = Serializer::hydrate_row( $row );
				$entry['_persistenceRowId'] = (int) ( $row['id'] ?? 0 );
				$entries[]                  = $entry;
			}
		}
		return $entries;
	}

	private static function same_owner_and_scope( array $apply, array $entry ): bool {
		$scope = (string) ( $apply['document']['scopeKey'] ?? '' );
		return '' !== $scope && $scope === (string) ( $entry['document']['scopeKey'] ?? '' )
			&& (int) ( $apply['userId'] ?? 0 ) === (int) ( $entry['userId'] ?? 0 );
	}

	private static function is_apply( array $entry ): bool {
		return in_array( $entry['type'] ?? '', [ ...PersistenceOutcome::APPLY_TYPES, 'apply_post_blocks_suggestion' ], true );
	}

	private static function resolve( array $entry, array $evidence ): array {
		$latest = null;
		foreach ( $evidence as $row ) {
			$outcome = $row['after']['outcome'];
			if ( ! in_array( $outcome['event'], PersistenceOutcome::SERVER_EVENTS, true ) || ! is_int( $outcome['saveSequence'] ?? null ) || $outcome['saveSequence'] <= 0 ) {
				continue;
			}
			if ( null === $latest || $outcome['saveSequence'] > $latest['saveSequence'] ) {
				$latest = array_merge( $outcome, [ 'saveOccurrenceId' => $row['saveOccurrenceId'] ] );
			} elseif ( $outcome['saveSequence'] === $latest['saveSequence'] && ( $outcome['event'] !== $latest['event'] || $row['saveOccurrenceId'] !== $latest['saveOccurrenceId'] ) ) {
				$latest['event']  = 'save_unverifiable';
				$latest['reason'] = 'conflicting_verdicts';
			}
		}
		$eligibility    = self::is_apply( $entry ) ? PersistenceOccurrenceRepository::eligibility( $entry ) : [
			'eligible' => false,
			'pending'  => false,
		];
		$compared       = null !== $latest;
		$eligible       = ! empty( $eligibility['eligible'] ) || $compared;
		$state          = $latest['event'] ?? 'unknown';
		$conclusive     = in_array( $state, [ 'save_confirmed', 'save_discarded' ], true );
		$reason         = (string) ( $latest['reason'] ?? '' );
		$verdict_labels = [
			'unknown'           => __( 'Persistence unknown', 'flavor-agent' ),
			'save_confirmed'    => __( 'Persisted', 'flavor-agent' ),
			'save_discarded'    => 'partial_persistence' === $reason ? __( 'Partially persisted', 'flavor-agent' ) : __( 'Not persisted', 'flavor-agent' ),
			'save_unverifiable' => __( 'Could not verify saved change', 'flavor-agent' ),
		];
		$coverage_state = $compared ? 'compared' : ( $eligible ? 'not_verified' : 'not_eligible' );
		$coverage_label = $compared
			? __( 'Compared with saved content', 'flavor-agent' )
			: ( $eligible ? __( 'Not verified', 'flavor-agent' ) : __( 'Not eligible for save verification', 'flavor-agent' ) );
		return [
			'persistenceVerdict'   => [
				'state'            => $state,
				'label'            => $verdict_labels[ $state ],
				'saveSequence'     => (int) ( $latest['saveSequence'] ?? 0 ),
				'saveOccurrenceId' => $latest['saveOccurrenceId'] ?? null,
				'reason'           => $reason,
			],
			'verificationCoverage' => [
				'state'      => $coverage_state,
				'eligible'   => $eligible,
				'compared'   => $compared,
				'conclusive' => $conclusive,
				'label'      => $coverage_label,
			],
			'requestStatus'        => self::request_status( $entry, $evidence ),
			'undoState'            => self::undo_state( $entry ),
		];
	}

	private static function request_status( array $entry, array $evidence ): array {
		$occurrences = [];
		foreach ( $evidence as $row ) {
			$event = $row['after']['outcome']['event'];
			if ( ! in_array( $event, PersistenceOutcome::CLIENT_EVENTS, true ) ) {
				continue;
			}
			$id                   = $row['saveOccurrenceId'];
			$occurrences[ $id ] ??= [
				'failed'        => false,
				'attemptOrder'  => null,
				'fallbackOrder' => [ 0, 0 ],
			];
			$observed_at          = (string) ( $row['after']['outcome']['observedAt'] ?? $row['timestamp'] ?? '' );
			$timestamp            = '' !== $observed_at && false !== strtotime( $observed_at ) ? (float) ( new \DateTimeImmutable( $observed_at ) )->format( 'U.u' ) : 0.0;
			$order                = [ $timestamp, (int) ( $row['_persistenceRowId'] ?? 0 ) ];
			if ( 'save_attempted' === $event ) {
				$occurrences[ $id ]['attemptOrder'] = $order;
			} else {
				$occurrences[ $id ]['failed'] = true;
			}
			$occurrences[ $id ]['fallbackOrder'] = max( $occurrences[ $id ]['fallbackOrder'], $order );
		}
		$latest = null;
		foreach ( $occurrences as $occurrence ) {
			$occurrence['order'] = $occurrence['attemptOrder'] ?? $occurrence['fallbackOrder'];
			if ( null === $latest || $occurrence['order'] > $latest['order'] ) {
				$latest = $occurrence;
			}
		}
		if ( null !== $latest ) {
			return $latest['failed']
				? [
					'state' => 'save_failed',
					'label' => __( 'Save request failed', 'flavor-agent' ),
				]
				: [
					'state' => 'save_attempted',
					'label' => __( 'Save requested', 'flavor-agent' ),
				];
		}
		$state  = (string) ( $entry['apply']['status'] ?? $entry['request']['apply']['status'] ?? $entry['executionResult'] ?? 'unknown' );
		$labels = [
			'applied'    => 'editor-state' === ( $entry['applyLane'] ?? '' ) ? __( 'Changed in editor', 'flavor-agent' ) : __( 'Applied', 'flavor-agent' ),
			'pending'    => __( 'Awaiting approval', 'flavor-agent' ),
			'failed'     => __( 'Request failed', 'flavor-agent' ),
			'rejected'   => __( 'Rejected', 'flavor-agent' ),
			'expired'    => __( 'Expired', 'flavor-agent' ),
			'diagnostic' => __( 'Diagnostic', 'flavor-agent' ),
			'no_changes' => __( 'No changes', 'flavor-agent' ),
			'unknown'    => __( 'Request status unknown', 'flavor-agent' ),
		];
		return [
			'state' => isset( $labels[ $state ] ) ? $state : 'unknown',
			'label' => $labels[ $state ] ?? $labels['unknown'],
		];
	}

	private static function undo_state( array $entry ): array {
		$state  = (string) ( $entry['undo']['status'] ?? 'not_applicable' );
		$labels = [
			'undone'         => __( 'Undone in editor', 'flavor-agent' ),
			'available'      => __( 'Not undone', 'flavor-agent' ),
			'review'         => __( 'Undo needs review', 'flavor-agent' ),
			'failed'         => __( 'Undo failed', 'flavor-agent' ),
			'not_applicable' => __( 'Undo not applicable', 'flavor-agent' ),
		];
		return [
			'state' => isset( $labels[ $state ] ) ? $state : 'not_applicable',
			'label' => $labels[ $state ] ?? $labels['not_applicable'],
		];
	}

	private static function rate( int $numerator, int $denominator ): float {
		return 0 === $denominator ? 0.0 : round( $numerator / $denominator, 4 );
	}
}
