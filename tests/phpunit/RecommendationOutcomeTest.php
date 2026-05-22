<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\RecommendationOutcome;
use PHPUnit\Framework\TestCase;

final class RecommendationOutcomeTest extends TestCase {

	public function test_normalizes_privacy_safe_outcome_entry(): void {
		$entry = RecommendationOutcome::normalize_entry(
			[
				'type'       => 'recommendation_outcome',
				'surface'    => 'pattern',
				'suggestion' => str_repeat( 'Pattern inserted from shelf ', 10 ),
				'target'     => [
					'recommendationSetId' => 'set-1',
					'suggestionKey'       => 'suggestion-1',
					'patternKey'          => 'theme/hero',
					'patternTitle'        => 'Private launch hero',
					'rank'                => 2,
				],
				'after'      => [
					'outcome' => [
						'event'                  => 'pattern_inserted_from_shelf',
						'visibility'             => 'public',
						'recommendationSetId'    => 'set-1',
						'sourceRequestSignature' => 'hash_abc123',
						'reason'                 => 'insert blocks success',
						'topSuggestionKeys'      => [ 'a', 'b', 'c', 'd' ],
						'resultCount'            => 4,
						'rawPrompt'              => 'Make my private content better.',
					],
				],
				'request'    => [
					'prompt' => 'Make my private content better.',
				],
				'undo'       => [
					'status' => 'available',
				],
			]
		);

		$this->assertIsArray( $entry );
		$this->assertSame( 'recommendation_outcome', $entry['type'] );
		$this->assertSame( 'diagnostic', $entry['executionResult'] );
		$this->assertSame( [ 'status' => 'not_applicable' ], $entry['undo'] );
		$this->assertTrue( $entry['diagnostic'] );
		$this->assertSame( 'diagnostic', $entry['after']['outcome']['visibility'] );
		$this->assertSame( 'insert_blocks_success', $entry['after']['outcome']['reason'] );
		$this->assertSame( [ 'a', 'b', 'c' ], $entry['after']['outcome']['topSuggestionKeys'] );
		$this->assertArrayNotHasKey( 'patternTitle', $entry['target'] );
		$this->assertArrayNotHasKey( 'prompt', $entry['request'] );
		$this->assertArrayNotHasKey( 'rawPrompt', $entry['after']['outcome'] );
		$this->assertLessThanOrEqual( 96, strlen( $entry['suggestion'] ) );
	}

	public function test_rejects_unknown_outcome_event(): void {
		$result = RecommendationOutcome::normalize_entry(
			[
				'type'    => 'recommendation_outcome',
				'surface' => 'block',
				'after'   => [
					'outcome' => [
						'event' => 'dismissed',
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'flavor_agent_activity_invalid_outcome_event',
			$result->get_error_code()
		);
	}
}
