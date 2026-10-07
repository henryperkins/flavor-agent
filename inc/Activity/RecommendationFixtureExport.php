<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

use FlavorAgent\Support\ValidationReason;

/** Projects authorized stored rows; raw activity is never an export payload. */
final class RecommendationFixtureExport {

	private array $aliases = [];
	private string $nonce;

	private function __construct() {
		$this->nonce = bin2hex( random_bytes( 8 ) );
	}

	public static function validate_selection( mixed $selection ): array|\WP_Error {
		$keys = [ 'dateFrom', 'dateTo', 'surfaces', 'rowLimit' ];
		if ( ! RecommendationFixtureSchema::object_keys( $selection, $keys, $keys )
			|| ! is_int( $selection['rowLimit'] ) || $selection['rowLimit'] < 1 || $selection['rowLimit'] > RecommendationFixtureSchema::MAX_ROWS
			|| ! RecommendationFixtureSchema::surfaces( $selection['surfaces'] ) || [] === $selection['surfaces'] ) {
			return self::invalid_selection();
		}
		$from = self::date_bound( $selection['dateFrom'], false );
		$to   = self::date_bound( $selection['dateTo'], true );
		if ( null === $from || null === $to || $from > $to || $to->getTimestamp() - $from->getTimestamp() >= 31 * 86400 ) {
			return self::invalid_selection();
		}
		return $selection;
	}

	/** Called with validated selection; exact cutoffs stay outside the candidate. */
	public static function selection_bounds( array $selection ): array {
		return [
			'from' => self::date_bound( $selection['dateFrom'], false )->format( 'Y-m-d H:i:s' ),
			'to'   => self::date_bound( $selection['dateTo'], true )->format( 'Y-m-d H:i:s' ),
		];
	}

	private static function date_bound( mixed $value, bool $end ): ?\DateTimeImmutable {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2}:[0-9]{2}Z)?$/D', $value ) ) {
			return null;
		}
		$format = 10 === strlen( $value ) ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z';
		$date   = \DateTimeImmutable::createFromFormat( '!' . $format, $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( $format ) !== $value ) {
			return null;
		}
		return 'Y-m-d' === $format && $end ? $date->setTime( 23, 59, 59 ) : $date;
	}

	public static function build( array $entries, array $selection, array $provenance ): array|\WP_Error {
		if ( ! RecommendationFixtureSchema::object_keys( $selection, [ 'dateFrom', 'dateTo', 'surfaces', 'rowLimit', 'truncated', 'eligibleRowCount' ] ) ) {
			return self::invalid_selection();
		}
		$validated = self::validate_selection( array_intersect_key( $selection, array_flip( [ 'dateFrom', 'dateTo', 'surfaces', 'rowLimit' ] ) ) );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( ! RecommendationFixtureSchema::provenance( $provenance ) ) {
			return new \WP_Error( 'flavor_agent_fixture_invalid_provenance', 'Verified runtime provenance is required.', [ 'status' => 409 ] );
		}
		$exporter  = new self();
		$limit     = $selection['rowLimit'];
		$sample    = array_slice( array_values( $entries ), 0, $limit );
		$rows      = [];
		$supported = [];
		$seen      = [];
		foreach ( $sample as $entry ) {
			if ( ! is_array( $entry ) || ! in_array( $entry['surface'] ?? null, $selection['surfaces'], true ) ) {
				continue;
			}
			$row = $exporter->project( $entry );
			if ( null === $row || isset( $seen[ $row['aliases']['row'] ] ) ) {
				continue;
			}
			$seen[ $row['aliases']['row'] ] = true;
			$rows[]                         = $row;
			$supported[]                    = $entry;
		}
		$populations = self::populations( $supported );
		$candidate   = [
			'schemaVersion' => RecommendationFixtureSchema::VERSION,
			'provenance'    => [
				...$provenance,
				'config' => [
					'surfaces'    => $selection['surfaces'],
					'rankingMode' => 'static',
				],
			],
			'sample'        => [
				'rowLimit'             => $limit,
				'sampleSize'           => count( $sample ),
				'exportedRowCount'     => count( $rows ),
				'excludedRowCount'     => count( $sample ) - count( $rows ),
				'truncated'            => count( $entries ) > $limit || true === ( $selection['truncated'] ?? false ),
				'shownSetCount'        => $populations['shownSetCount'],
				'shownSuggestionCount' => $populations['shownSuggestionCount'],
				'unlinkedApplyCount'   => $populations['unlinkedApplyCount'],
				'missingIdentityCount' => $populations['missingIdentityCount'],
			],
			'rows'          => $rows,
			'coverage'      => [
				'surfaces' => $selection['surfaces'],
				...$populations['coverage'],
			],
			'metrics'       => $populations['metrics'],
		];
		if ( isset( $selection['eligibleRowCount'] ) && is_int( $selection['eligibleRowCount'] ) && $selection['eligibleRowCount'] >= count( $sample ) ) {
			$candidate['sample']['eligibleRowCount'] = $selection['eligibleRowCount'];
		}
		if ( ! RecommendationFixtureSchema::validate( $candidate ) ) {
			return new \WP_Error( 'flavor_agent_fixture_invalid_projection', 'The candidate failed the closed export schema.', [ 'status' => 422 ] );
		}
		return $candidate;
	}

	private function project( array $entry ): ?array {
		if ( 1 !== ( $entry['schemaVersion'] ?? null ) || ! in_array( $entry['surface'] ?? null, RecommendationOutcome::SURFACES, true ) || '' === self::identity( $entry['id'] ?? null ) ) {
			return null;
		}
		$type     = $entry['type'] ?? null;
		$is_apply = in_array( $type, RecommendationFixtureSchema::APPLY_TYPES, true );
		$outcome  = is_array( $entry['after']['outcome'] ?? null ) ? $entry['after']['outcome'] : [];
		$event    = $is_apply ? $type : ( $outcome['event'] ?? null );
		if ( ! $is_apply && ( RecommendationOutcome::TYPE !== $type || ! in_array( $event, RecommendationOutcome::EVENTS, true ) || PersistenceOutcome::is_lifecycle_event( $event ) ) ) {
			return null;
		}
		$metadata   = $is_apply ? ( is_array( $entry['request']['recommendation'] ?? null ) ? $entry['request']['recommendation'] : [] ) : $outcome;
		$set        = self::identity( $metadata['recommendationSetId'] ?? $entry['target']['recommendationSetId'] ?? null );
		$suggestion = self::identity( $metadata['suggestionKey'] ?? $entry['suggestionKey'] ?? $entry['target']['suggestionKey'] ?? null );
		$shown      = RecommendationOutcome::shown_suggestion_keys( $outcome['shownSuggestionKeys'] ?? [] );
		if ( 'shown' === $event ) {
			// A singular alias must never stand in for a multi-suggestion visible list.
			$suggestion = 1 === count( $shown ) ? $shown[0] : '';
		}
		if ( 'dismissed' === $event && ( '' === $set || '' === $suggestion || 'user_dismissed' !== ( $outcome['reason'] ?? null ) ) ) {
			return null;
		}
		$row         = [
			'aliases' => [ 'row' => $this->alias( 'row', $entry['id'] ) ],
			'surface' => $entry['surface'],
			'event'   => $event,
		];
		$attribution = is_array( $metadata['learningAttribution'] ?? null ) ? $metadata['learningAttribution'] : [];
		$joins       = [
			'set'             => $set,
			'suggestion'      => $suggestion,
			'generation'      => $attribution['generationId'] ?? null,
			'sourceSignature' => $metadata['sourceRequestSignature'] ?? null,
			'guideline'       => $attribution['guidelineVersion'] ?? null,
			'docsContent'     => $attribution['docsContentFingerprint'] ?? null,
			'docsRuntime'     => $attribution['docsRuntimeFingerprint'] ?? null,
			'linkedApply'     => $entry['linkedApplyActivityId'] ?? null,
			'saveOccurrence'  => $entry['saveOccurrenceId'] ?? null,
		];
		foreach ( $joins as $key => $value ) {
			if ( '' !== self::identity( $value ) ) {
				// Set/suggestion joins include their supported parent scope, never site/entity/request IDs.
				$join = match ( $key ) {
					'set' => self::tuple_key( $entry['surface'], $value ),
					'suggestion' => self::tuple_key( $entry['surface'], $set, $value ),
					default => $value,
				};
				$row['aliases'][ $key ] = $this->alias( $key, $join );
			}
		}
		$reason = $outcome['reason'] ?? null;
		if ( in_array( $reason, [ ...RecommendationFixtureSchema::FIXED_REASONS, ...array_keys( ValidationReason::vocabulary() ) ], true ) ) {
			$row['reason'] = $reason;
		}
		if ( is_string( $reason ) && array_key_exists( $reason, ValidationReason::vocabulary() ) ) {
			$row['validationReason'] = $reason;
		}
		$states = [
			'executionResult'      => $entry['executionResult'] ?? null,
			'undoStatus'           => $entry['undo']['status'] ?? null,
			'persistenceVerdict'   => $entry['persistenceVerdict']['state'] ?? null,
			'verificationCoverage' => $entry['verificationCoverage']['state'] ?? null,
		];
		foreach ( $states as $key => $value ) {
			if ( in_array( $value, RecommendationFixtureSchema::STATE_ENUMS[ $key ], true ) ) {
				$row['state'][ $key ] = $value;
			}
		}
		$ranking = is_array( $metadata['ranking'] ?? null ) ? $metadata['ranking'] : [];
		foreach ( [
			'score'        => 'blendedScore',
			'contextScore' => 'contextScore',
		] as $key => $source ) {
			if ( RecommendationFixtureSchema::number( $ranking[ $source ] ?? null ) ) {
				$row['ranking'][ $key ] = $ranking[ $source ];
			}
		}
		if ( is_int( $metadata['rank'] ?? null ) && $metadata['rank'] > 0 && $metadata['rank'] <= RecommendationFixtureSchema::MAX_ROWS ) {
			$row['ranking']['rank'] = $metadata['rank'];
		}
		foreach ( [
			'contextEvidence'  => RecommendationFixtureSchema::EVIDENCE_KEYS,
			'contextPenalties' => RecommendationFixtureSchema::PENALTY_KEYS,
		] as $key => $allowed ) {
			if ( isset( $ranking[ $key ] ) && [] !== $ranking[ $key ] && RecommendationFixtureSchema::numeric_map( $ranking[ $key ], $allowed ) ) {
				$row['ranking'][ $key ] = $ranking[ $key ];
			}
		}
		$traits = $metadata['patternTraits'] ?? null;
		if ( is_array( $traits ) && array_is_list( $traits ) && count( $traits ) <= 8 ) {
			$valid = array_filter( $traits, static fn ( mixed $pattern_trait ): bool => in_array( $pattern_trait, RecommendationOutcome::PATTERN_TRAITS, true ) );
			if ( count( $valid ) === count( $traits ) && [] !== $traits ) {
				$row['patternTraits'] = array_values( array_unique( $traits ) );
			}
		}
		return $row;
	}

	private function alias( string $kind, string $identity ): string {
		if ( 'linkedApply' === $kind ) {
			// A link uses the same opaque ordinal as its row while retaining the fixed field prefix.
			$row = $this->alias( 'row', $identity );
			return 'linkedApply' . substr( $row, 3 );
		}
		$this->aliases[ $kind ] ??= [];
		if ( ! isset( $this->aliases[ $kind ][ $identity ] ) ) {
			$this->aliases[ $kind ][ $identity ] = $kind . '-' . $this->nonce . '-' . ( count( $this->aliases[ $kind ] ) + 1 );
		}
		return $this->aliases[ $kind ][ $identity ];
	}

	private static function identity( mixed $value ): string {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 512 ? $value : '';
	}

	/** Length-prefixed tuple parts remain distinct even with delimiters or invalid UTF-8. */
	private static function tuple_key( string ...$parts ): string {
		return implode( '', array_map( static fn ( string $part ): string => strlen( $part ) . ':' . $part, $parts ) );
	}

	private static function populations( array $entries ): array {
		$shown             = [];
		$shown_suggestions = [];
		$selected          = [];
		$applied           = [];
		$pattern_shown     = [];
		$pattern_inserted  = [];
		$attempted         = [];
		$blocked           = [];
		$counts            = array_fill_keys( [ 'apply', 'undone', 'patternAttempts', 'insertFailed', 'unlinked', 'missingIdentity', 'missingShownIdentity', 'eligible', 'compared', 'conclusive', 'confirmed' ], 0 );
		foreach ( $entries as $entry ) {
			$is_apply                   = in_array( $entry['type'], RecommendationFixtureSchema::APPLY_TYPES, true );
			$meta                       = $is_apply ? ( $entry['request']['recommendation'] ?? [] ) : ( $entry['after']['outcome'] ?? [] );
			$set                        = self::identity( $meta['recommendationSetId'] ?? $entry['target']['recommendationSetId'] ?? null );
			$key                        = self::identity( $meta['suggestionKey'] ?? $entry['suggestionKey'] ?? $entry['target']['suggestionKey'] ?? null );
			$set_key                    = '' === $set ? '' : self::tuple_key( $entry['surface'], $set );
			$event                      = $is_apply ? $entry['type'] : $meta['event'];
			$shown_keys                 = RecommendationOutcome::shown_suggestion_keys( $meta['shownSuggestionKeys'] ?? [] );
			$missing                    = '' === $set || ( 'shown' === $event ? [] === $shown_keys : '' === $key );
			$counts['missingIdentity'] += $missing ? 1 : 0;
			if ( $is_apply ) {
				++$counts['apply'];
				$counts['undone'] += 'undone' === ( $entry['undo']['status'] ?? '' ) ? 1 : 0;
				if ( $missing ) {
					++$counts['unlinked'];
				} else {
					$applied[ $set_key ] = true;
					$attempted[ self::tuple_key( $entry['surface'], $set, $key ) ] = true;
				}
				// These cohorts are supplied only by the authorized server resolver, never inferred from apply.
				$coverage = $entry['verificationCoverage'] ?? [];
				foreach ( [ 'eligible', 'compared', 'conclusive' ] as $cohort ) {
					$counts[ $cohort ] += true === ( $coverage[ $cohort ] ?? false ) ? 1 : 0;
				}
				$counts['confirmed'] += 'save_confirmed' === ( $entry['persistenceVerdict']['state'] ?? '' ) ? 1 : 0;
				continue;
			}
			if ( 'shown' === $event ) {
				$counts['missingShownIdentity'] += $missing ? 1 : 0;
				if ( '' !== $set_key ) {
					$shown[ $set_key ] = true;
					foreach ( $shown_keys as $suggestion ) {
						$shown_suggestions[ self::tuple_key( $entry['surface'], $set, $suggestion ) ] = true;
					}
					if ( 'pattern' === $entry['surface'] ) {
						$pattern_shown[ $set_key ] = true;
					}
				}
				continue;
			}
			if ( 'selected_for_review' === $event && '' !== $set_key ) {
				$selected[ $set_key ] = true;
			}
			if ( 'pattern_inserted_from_shelf' === $event && '' !== $set_key ) {
				$pattern_inserted[ $set_key ] = true;
			}
			if ( in_array( $event, [ 'pattern_inserted_from_shelf', 'adapted_inserted_from_preview', 'insert_failed', 'adapted_insert_failed' ], true ) ) {
				++$counts['patternAttempts'];
				$counts['insertFailed'] += in_array( $event, [ 'insert_failed', 'adapted_insert_failed' ], true ) ? 1 : 0;
			}
			if ( '' !== $set_key && in_array( $event, [ 'selected_for_review', 'pattern_inserted_from_shelf', 'stale_blocked', 'validation_blocked' ], true ) ) {
				$event_key               = self::tuple_key( $entry['surface'], $event, $set, $key, is_string( $meta['reason'] ?? null ) ? $meta['reason'] : '' );
				$attempted[ $event_key ] = true;
				if ( 'validation_blocked' === $event ) {
					$blocked[ $event_key ] = true;
				}
			}
		}
		$missing_shown = count( array_diff_key( $selected + $applied + $pattern_inserted, $shown ) ) + $counts['missingShownIdentity'];
		$missing_apply = $counts['unlinked'];
		$unverified    = max( 0, $counts['eligible'] - $counts['compared'] );
		$incomplete    = $missing_shown > 0 || $missing_apply > 0 || $counts['missingIdentity'] > 0;
		$definitions   = [
			'reviewSelectionRate'      => [ count( $selected ), count( $shown ) ],
			'applyConversionRate'      => [ count( array_intersect_key( $applied, $shown ) ), count( $shown ) ],
			'patternInsertionRate'     => [ count( $pattern_inserted ), count( $pattern_shown ) ],
			'undoRate'                 => [ $counts['undone'], $counts['apply'] ],
			'validationBlockedRate'    => [ count( $blocked ), count( $attempted ) ],
			'insertFailedRate'         => [ $counts['insertFailed'], $counts['patternAttempts'] ],
			'savePersistedRate'        => [ $counts['confirmed'], $counts['conclusive'] ],
			'saveDiscardedRate'        => [ max( 0, $counts['conclusive'] - $counts['confirmed'] ), $counts['conclusive'] ],
			'saveUnverifiableRate'     => [ max( 0, $counts['compared'] - $counts['conclusive'] ), $counts['compared'] ],
			'verificationCoverageRate' => [ $counts['compared'], $counts['eligible'] ],
		];
		$metrics       = [];
		foreach ( $definitions as $name => [ $numerator, $denominator ] ) {
			$missing   = str_starts_with( $name, 'save' ) || 'verificationCoverageRate' === $name ? $unverified > 0 : $incomplete;
			$metrics[] = [
				'name'        => $name,
				'numerator'   => $numerator,
				'denominator' => $denominator,
				...( $denominator > 0 ? [ 'value' => round( $numerator / $denominator, 4 ) ] : [] ),
				'coverage'    => 0 === $denominator ? 'no_denominator' : ( $missing ? 'missing_links' : 'complete_sample' ),
			];
		}
		return [
			'shownSetCount'        => count( $shown ),
			'shownSuggestionCount' => count( $shown_suggestions ),
			'unlinkedApplyCount'   => $counts['unlinked'],
			'missingIdentityCount' => $counts['missingIdentity'],
			'coverage'             => [
				'missingShownLinks'       => $missing_shown,
				'missingApplyLinks'       => $missing_apply,
				'unverifiedCoverageCount' => $unverified,
			],
			'metrics'              => $metrics,
		];
	}

	private static function invalid_selection(): \WP_Error {
		return new \WP_Error( 'flavor_agent_fixture_invalid_selection', 'Choose supported surfaces, 1–1000 rows, and an inclusive UTC date range of at most 31 days.', [ 'status' => 400 ] );
	}
}
