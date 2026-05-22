<?php

declare(strict_types=1);

namespace FlavorAgent\Activity;

final class RecommendationOutcome {

	public const TYPE = 'recommendation_outcome';

	private const MAX_LABEL_LENGTH   = 96;
	private const MAX_STRING_LENGTH  = 191;
	private const TOP_SUGGESTION_CAP = 3;

	private const EVENTS = [
		'shown',
		'selected_for_review',
		'stale_blocked',
		'validation_blocked',
		'pattern_inserted_from_shelf',
	];

	private const SURFACES = [
		'block',
		'template',
		'template-part',
		'global-styles',
		'style-book',
		'pattern',
		'navigation',
		'content',
	];

	private const EVENT_LABELS = [
		'shown'                       => 'Recommendations shown',
		'selected_for_review'         => 'Recommendation selected for review',
		'stale_blocked'               => 'Recommendation blocked by stale context',
		'validation_blocked'          => 'Recommendation blocked by validation',
		'pattern_inserted_from_shelf' => 'Pattern inserted from recommendation shelf',
	];

	/**
	 * @param array<string, mixed> $entry
	 */
	public static function is_outcome_entry( array $entry ): bool {
		return self::TYPE === trim( (string) ( $entry['type'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function normalize_entry( array $entry ) {
		if ( ! self::is_outcome_entry( $entry ) ) {
			return $entry;
		}

		$surface = self::normalize_enum(
			$entry['surface'] ?? '',
			self::SURFACES
		);

		if ( '' === $surface ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_outcome_surface',
				'Recommendation outcome entries require a supported surface.',
				[ 'status' => 400 ]
			);
		}

		$after   = is_array( $entry['after'] ?? null ) ? $entry['after'] : [];
		$outcome = is_array( $after['outcome'] ?? null ) ? $after['outcome'] : [];
		$event   = self::normalize_enum(
			$outcome['event'] ?? '',
			self::EVENTS
		);

		if ( '' === $event ) {
			return new \WP_Error(
				'flavor_agent_activity_invalid_outcome_event',
				'Recommendation outcome entries require a supported event.',
				[ 'status' => 400 ]
			);
		}

		$target         = self::normalize_target( $entry['target'] ?? [] );
		$suggestion_key = self::bounded_string(
			$entry['suggestionKey'] ?? $target['suggestionKey'] ?? $outcome['suggestionKey'] ?? null
		);
		$set_id         = self::bounded_string(
			$outcome['recommendationSetId'] ?? $target['recommendationSetId'] ?? ''
		);

		if ( '' !== $set_id ) {
			$target['recommendationSetId'] = $set_id;
		}

		if ( '' !== $suggestion_key ) {
			$target['suggestionKey'] = $suggestion_key;
		}

		$normalized_outcome = [
			'event'                  => $event,
			'visibility'             => 'diagnostic',
			'recommendationSetId'    => $set_id,
			'sourceRequestSignature' => self::bounded_string( $outcome['sourceRequestSignature'] ?? '' ),
			'reason'                 => self::normalize_reason( $outcome['reason'] ?? '' ),
			'topSuggestionKeys'      => self::normalize_string_list(
				$outcome['topSuggestionKeys'] ?? [],
				self::TOP_SUGGESTION_CAP
			),
			'resultCount'            => self::normalize_non_negative_int( $outcome['resultCount'] ?? 0 ),
		];
		$suggestion         = self::bounded_string(
			$entry['suggestion'] ?? self::EVENT_LABELS[ $event ],
			self::MAX_LABEL_LENGTH
		);

		return [
			...$entry,
			'type'            => self::TYPE,
			'surface'         => $surface,
			'target'          => $target,
			'suggestion'      => '' !== $suggestion ? $suggestion : self::EVENT_LABELS[ $event ],
			'suggestionKey'   => '' !== $suggestion_key ? $suggestion_key : null,
			'before'          => [],
			'after'           => [
				'outcome' => $normalized_outcome,
			],
			'request'         => [
				'reference'      => self::build_reference( $surface, $event, $set_id, $suggestion_key ),
				'recommendation' => [
					'recommendationSetId'    => $set_id,
					'suggestionKey'          => $suggestion_key,
					'sourceRequestSignature' => $normalized_outcome['sourceRequestSignature'],
					'rank'                   => $target['rank'] ?? null,
				],
			],
			'executionResult' => 'diagnostic',
			'undo'            => [
				'status' => 'not_applicable',
			],
			'diagnostic'      => true,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<string, float|int>
	 */
	public static function evaluate_metrics( array $entries ): array {
		return RecommendationOutcomeMetrics::evaluate( $entries );
	}

	/**
	 * @param array<int, string> $allowed
	 */
	private static function normalize_enum( mixed $value, array $allowed ): string {
		$normalized = sanitize_key( (string) $value );

		return in_array( $normalized, $allowed, true ) ? $normalized : '';
	}

	private static function bounded_string( mixed $value, int $max_length = self::MAX_STRING_LENGTH ): string {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '';
		}

		$normalized = sanitize_text_field( (string) $value );

		return substr( $normalized, 0, $max_length );
	}

	private static function normalize_reason( mixed $value ): string {
		$normalized = strtolower( (string) $value );
		$normalized = (string) preg_replace( '/[^a-z0-9_-]+/', '_', $normalized );
		$normalized = trim( $normalized, '_' );

		return substr( $normalized, 0, 64 );
	}

	private static function normalize_non_negative_int( mixed $value ): int {
		$number = is_numeric( $value ) ? (int) $value : 0;

		return max( 0, $number );
	}

	/**
	 * @return array<int, string>
	 */
	private static function normalize_string_list( mixed $value, int $cap ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$items = [];
		foreach ( $value as $item ) {
			$normalized = self::bounded_string( $item );
			if ( '' !== $normalized ) {
				$items[ $normalized ] = $normalized;
			}
		}

		return array_slice( array_values( $items ), 0, $cap );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_target( mixed $value ): array {
		$source = is_array( $value ) ? $value : [];
		$target = [];

		foreach ( [ 'recommendationSetId', 'suggestionKey', 'patternKey', 'blockName', 'clientId' ] as $key ) {
			$normalized = self::bounded_string( $source[ $key ] ?? '' );
			if ( '' !== $normalized ) {
				$target[ $key ] = $normalized;
			}
		}

		if ( isset( $source['rank'] ) && is_numeric( $source['rank'] ) ) {
			$target['rank'] = max( 0, (int) $source['rank'] );
		}

		return $target;
	}

	private static function build_reference( string $surface, string $event, string $set_id, string $suggestion_key ): string {
		return implode(
			':',
			array_filter(
				[ 'outcome', $surface, $event, $set_id, $suggestion_key ],
				static fn ( string $value ): bool => '' !== $value
			)
		);
	}
}
