<?php

declare(strict_types=1);

namespace FlavorAgent\Apply;

/**
 * Keep an intentionally fail-closed guarded query out of persistent wpdb error
 * diagnostics. Query filters still observe normal SQL, as they do for every
 * WordPress post write; this class prevents a deliberate guard mismatch from
 * promoting content-bearing SQL into error logs or retained diagnostics.
 */
final class GuardedQueryDiagnostics {

	private bool $finished = false;

	private function __construct(
		private readonly object $database,
		private readonly string $marker,
		private readonly mixed $previous_suppression,
		private readonly mixed $previous_last_query,
		private readonly mixed $previous_func_call,
		private readonly int $ezsql_start,
		private readonly int $queries_start
	) {
	}

	public static function begin( object $database, string $marker ): ?self {
		global $EZSQL_ERROR;

		if ( '' === $marker || ! is_callable( [ $database, 'suppress_errors' ] ) ) {
			return null;
		}

		$queries = is_array( $database->queries ?? null ) ? $database->queries : [];

		return new self(
			$database,
			$marker,
			$database->suppress_errors( true ),
			$database->last_query ?? null,
			$database->func_call ?? null,
			is_array( $EZSQL_ERROR ?? null ) ? count( $EZSQL_ERROR ) : 0,
			count( $queries )
		);
	}

	public function finish(): void {
		global $EZSQL_ERROR;

		if ( $this->finished ) {
			return;
		}

		$this->finished = true;
		$this->database->suppress_errors( $this->previous_suppression );

		if ( is_array( $EZSQL_ERROR ?? null ) ) {
			foreach ( $EZSQL_ERROR as $index => $entry ) {
				if ( $index < $this->ezsql_start || ! is_array( $entry ) ) {
					continue;
				}

				$query = (string) ( $entry['query'] ?? '' );

				if ( str_contains( $query, $this->marker ) ) {
					$EZSQL_ERROR[ $index ]['query'] = '[Flavor Agent guarded query redacted]';
				}
			}
		}

		if ( is_array( $this->database->queries ?? null ) ) {
			foreach ( $this->database->queries as $index => $entry ) {
				if ( $index < $this->queries_start || ! is_array( $entry ) ) {
					continue;
				}

				if ( str_contains( (string) ( $entry[0] ?? '' ), $this->marker ) ) {
					$this->database->queries[ $index ][0] = '[Flavor Agent guarded query redacted]';
				}
			}
		}

		if ( str_contains( (string) ( $this->database->last_query ?? '' ), $this->marker ) ) {
			$this->database->last_query = $this->previous_last_query;
		}

		if ( str_contains( (string) ( $this->database->func_call ?? '' ), $this->marker ) ) {
			$this->database->func_call = $this->previous_func_call;
		}
	}
}
