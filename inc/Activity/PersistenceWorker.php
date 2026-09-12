<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

/** Compare frozen versions in bounded, retryable background batches. */
final class PersistenceWorker {

	public static function run(): array {
		$started   = microtime( true );
		$processed = 0;
		$failed    = false;
		foreach ( PersistenceOccurrenceRepository::pending() as $snapshot ) {
			if ( strtotime( $snapshot['expiresAt'] . ' UTC' ) <= time() || ! array_key_exists( 'content', $snapshot ) ) {
				PersistenceOccurrenceRepository::expire( $snapshot );
				continue;
			}
			$cursor     = (int) $snapshot['cursorId'];
			$conclusive = true;
			$rows       = PersistenceOccurrenceRepository::candidate_rows( $snapshot, $cursor );
			foreach ( $rows as $row ) {
				try {
					$apply      = Serializer::hydrate_row( $row );
					$comparison = PersistenceComparison::compare( $apply, $snapshot );
					$result     = PersistenceOutcome::write_verdict( $apply, $snapshot, $comparison );
					if ( is_wp_error( $result ) ) {
						$failed = true;
						break;
					}
					$conclusive = $conclusive && in_array( $comparison['event'], [ 'save_confirmed', 'save_discarded' ], true );
					$cursor     = (int) $row['id'];
					++$processed;
				} catch ( \Throwable $error ) {
					// A broken job is missing coverage, never evidence of a discard.
					unset( $error );
					$failed = true;
					break;
				}
				if ( microtime( true ) - $started >= 0.05 ) {
					break;
				}
			}
			$complete = ! $failed && [] === PersistenceOccurrenceRepository::candidate_rows( $snapshot, $cursor, 1 );
			PersistenceOccurrenceRepository::advance( $snapshot, $cursor, $complete, $conclusive );
		}
		if ( [] !== PersistenceOccurrenceRepository::pending() ) {
			PersistenceOccurrenceRepository::schedule( $failed ? 60 : 1 );
		}
		return [
			'processed' => $processed,
			'failed'    => $failed,
		];
	}
}
