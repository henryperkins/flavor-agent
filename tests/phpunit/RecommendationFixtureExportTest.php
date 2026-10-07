<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Activity\RecommendationFixtureExport;
use FlavorAgent\Activity\RecommendationFixtureSchema;
use FlavorAgent\Activity\RecommendationOutcome;
use FlavorAgent\Activity\FixtureExportProvenance;
use FlavorAgent\Activity\Repository;
use FlavorAgent\REST\FixtureExportController;
use FlavorAgent\Tests\Support\WordPressTestState;
use PHPUnit\Framework\TestCase;

final class RecommendationFixtureExportTest extends TestCase {

	protected function setUp(): void {
		WordPressTestState::reset();
		// Real wpdb clears errors per query; the shared fake retains its last error.
		$GLOBALS['wpdb']->last_error = '';
	}

	public function test_projection_drops_raw_nested_text_identifiers_and_precise_times(): void {
		$this->assertTrue( class_exists( RecommendationFixtureExport::class ) );
		$entries = $this->entries();
		$entries[1]['request']['recommendation']['ranking']['contextEvidence']['secret_prompt'] = 'Private nested text';
		$result = RecommendationFixtureExport::build( $entries, $this->selection(), $this->provenance() );
		$this->assertIsArray( $result );
		$this->assertSame( 'recommendation-fixture-export-v1', $result['schemaVersion'] );
		$this->assertTrue( RecommendationFixtureSchema::validate( $result ) );
		$json = json_encode( $result );
		foreach ( [ 'Private', 'set-secret', 'suggestion-secret', 'activity-secret', 'hash_private', '2026-10-07T', 'example.test', 'sk-secret', 'provider-private', 'model-private', 'userId', 'operations', 'secret_prompt' ] as $unsafe ) {
			$this->assertStringNotContainsString( $unsafe, $json );
		}
		$this->assertArrayNotHasKey( 'contextEvidence', $result['rows'][1]['ranking'] ?? [] );
	}

	public function test_aliases_join_within_one_export_and_never_across_exports(): void {
		$this->assertTrue( class_exists( RecommendationFixtureExport::class ) );
		$first  = RecommendationFixtureExport::build( $this->entries(), $this->selection(), $this->provenance() );
		$second = RecommendationFixtureExport::build( $this->entries(), $this->selection(), $this->provenance() );
		$this->assertSame( $first['rows'][0]['aliases']['set'], $first['rows'][1]['aliases']['set'] );
		$this->assertNotSame( $first['rows'][0]['aliases']['set'], $second['rows'][0]['aliases']['set'] );
		$this->assertArrayNotHasKey( 'provider', $first['provenance']['publicVersions'] );
	}

	public function test_colon_bearing_identity_tuples_keep_aliases_and_population_counts_distinct(): void {
		$entries = [];
		foreach ( [ [ 'a:b', 'c' ], [ 'a', 'b:c' ] ] as $index => [ $set, $suggestion ] ) {
			$shown       = $this->entries()[0];
			$shown['id'] = 'shown-' . $index;
			$shown['after']['outcome']['recommendationSetId'] = $set;
			$shown['after']['outcome']['shownSuggestionKeys'] = [ $suggestion ];
			$shown = RecommendationOutcome::normalize_entry( $shown );
			$this->assertIsArray( $shown );
			$entries[]                             = $shown;
			$blocked                               = $shown;
			$blocked['id']                         = 'blocked-' . $index;
			$blocked['suggestionKey']              = $suggestion;
			$blocked['after']['outcome']['event']  = 'validation_blocked';
			$blocked['after']['outcome']['reason'] = 'operation_validation_failed';
			$entries[]                             = $blocked;
			$apply                                 = $this->entries()[1];
			$apply['id']                           = 'apply-' . $index;
			$apply['suggestionKey']                = $suggestion;
			$apply['request']['recommendation']['recommendationSetId'] = $set;
			$apply['request']['recommendation']['suggestionKey']       = $suggestion;
			$entries[] = $apply;
		}
		$result = RecommendationFixtureExport::build( $entries, $this->selection(), $this->provenance() );
		$this->assertTrue( RecommendationFixtureSchema::validate( $result ) );
		$this->assertNotSame( $result['rows'][0]['aliases']['set'], $result['rows'][3]['aliases']['set'] );
		$this->assertNotSame( $result['rows'][0]['aliases']['suggestion'], $result['rows'][3]['aliases']['suggestion'] );
		$this->assertSame( $result['rows'][0]['aliases']['suggestion'], $result['rows'][2]['aliases']['suggestion'] );
		$this->assertSame( $result['rows'][3]['aliases']['suggestion'], $result['rows'][5]['aliases']['suggestion'] );
		$this->assertSame( 2, $result['sample']['shownSetCount'] );
		$this->assertSame( 2, $result['sample']['shownSuggestionCount'] );
		$metrics = array_column( $result['metrics'], null, 'name' );
		$this->assertSame( 2, $metrics['validationBlockedRate']['numerator'] );
		$this->assertSame( 4, $metrics['validationBlockedRate']['denominator'] );
		$this->assertSame( 0.5, $metrics['validationBlockedRate']['value'] );
	}

	public function test_unknown_schema_and_nonfinite_ranking_are_excluded_without_inventing_scores(): void {
		$this->assertTrue( class_exists( RecommendationFixtureExport::class ) );
		$entries = $this->entries();
		$entries[1]['request']['recommendation']['ranking'] = [
			'blendedScore' => INF,
			'contextScore' => 'secret',
		];
		$unknown                  = $entries[0];
		$unknown['schemaVersion'] = 999;
		$entries[]                = $unknown;
		$result                   = RecommendationFixtureExport::build( $entries, $this->selection(), $this->provenance() );
		$this->assertSame( 3, $result['sample']['sampleSize'] );
		$this->assertSame( 2, $result['sample']['exportedRowCount'] );
		$this->assertSame( 1, $result['sample']['excludedRowCount'] );
		$this->assertArrayNotHasKey( 'ranking', $result['rows'][1] );
	}

	public function test_zero_denominator_is_unavailable_and_truncated_total_is_not_the_row_limit(): void {
		$this->assertTrue( class_exists( RecommendationFixtureExport::class ) );
		$result = RecommendationFixtureExport::build(
			[],
			[
				...$this->selection(),
				'truncated' => true,
			],
			$this->provenance()
		);
		$this->assertArrayNotHasKey( 'eligibleRowCount', $result['sample'] );
		foreach ( $result['metrics'] as $metric ) {
			$this->assertSame( 0, $metric['denominator'] );
			$this->assertArrayNotHasKey( 'value', $metric );
			$this->assertSame( 'no_denominator', $metric['coverage'] );
		}
	}

	public function test_schema_fails_closed_on_nested_unknown_keys_bad_types_and_new_metric_names(): void {
		$this->assertTrue( class_exists( RecommendationFixtureExport::class ) );
		$candidate = RecommendationFixtureExport::build( $this->entries(), $this->selection(), $this->provenance() );
		foreach ( [ 'alias', 'ranking', 'state', 'provenance', 'sample', 'coverage', 'metric' ] as $field ) {
			$changed = $candidate;
			if ( 'alias' === $field ) {
				$changed['rows'][0]['aliases']['rawId'] = 'private'; } elseif ( 'ranking' === $field ) {
				$changed['rows'][0]['ranking']['contextEvidence']['prompt'] = 'private'; } elseif ( 'state' === $field ) {
					$changed['rows'][0]['state']['snapshot'] = [ 'secret' => 'private' ]; } elseif ( 'provenance' === $field ) {
					$changed['provenance']['publicVersions']['model'] = 'private'; } elseif ( 'sample' === $field ) {
							$changed['sample']['rowLimit'] = '500'; } elseif ( 'coverage' === $field ) {
							$changed['coverage']['site'] = 'private'; } else {
								$changed['metrics'][0]['name'] = 'dismissalRate'; }
							$this->assertFalse( RecommendationFixtureSchema::validate( $changed ), $field );
		}
	}

	public function test_missing_or_request_supplied_provenance_cannot_authorize_export(): void {
		$this->assertTrue( class_exists( FixtureExportController::class ) );
		WordPressTestState::$capabilities = [ 'manage_options' => true ];
		$this->assertInstanceOf( \WP_Error::class, FixtureExportProvenance::resolve() );
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/fixture-export' );
		$request->set_param( 'selection', $this->selection() );
		$request->set_param( 'provenance', $this->provenance() );
		$this->assertInstanceOf( \WP_Error::class, FixtureExportController::export( $request ) );
	}

	public function test_export_requires_administrator_even_with_a_post_edit_capability(): void {
		$this->assertTrue( class_exists( FixtureExportController::class ) );
		WordPressTestState::$capabilities = [ 'edit_post' => true ];
		$request                          = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/fixture-export' );
		$request->set_param( 'selection', $this->selection() );
		$result = FixtureExportController::export( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trusted_provenance_exports_only_candidate_and_runtime_drift_closes_it(): void {
		global $wp_version;
		define( 'FLAVOR_AGENT_VERSION', '0.1.1' );
		$wp_version = '7.1.3';
		$trusted    = $this->provenance();
		unset( $trusted['config'] );
		define(
			'FLAVOR_AGENT_FIXTURE_EXPORT_PROVENANCE',
			[
				...$trusted,
				'verified' => true,
			]
		);
		$this->assertIsArray( FixtureExportProvenance::resolve() );
		WordPressTestState::$capabilities = [ 'manage_options' => true ];
		Repository::install();
		$request = new \WP_REST_Request( 'POST', '/flavor-agent/v1/activity/fixture-export' );
		$request->set_param( 'selection', $this->selection() );
		$response = FixtureExportController::export( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( [ 'candidate' ], array_keys( $response->get_data() ) );
		$this->assertTrue( RecommendationFixtureSchema::validate( $response->get_data()['candidate'] ) );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );
		$wp_version = '7.2.0';
		$this->assertInstanceOf( \WP_Error::class, FixtureExportController::export( $request ) );
	}

	public function test_route_is_post_only_and_uses_privileged_permission_and_closed_selection_schema(): void {
		FixtureExportController::register_routes();
		$route = WordPressTestState::$rest_routes['/flavor-agent/v1/activity/fixture-export']['endpoints'][0];
		$this->assertSame( 'POST', $route['methods'] );
		$this->assertSame( [ FixtureExportController::class, 'permission' ], $route['permission_callback'] );
		$this->assertFalse( $route['args']['selection']['additionalProperties'] );
		$this->assertSame( 1000, $route['args']['selection']['properties']['rowLimit']['maximum'] );
	}

	public function test_date_surface_and_row_limit_selection_is_strict(): void {
		$this->assertTrue( class_exists( RecommendationFixtureExport::class ) );
		foreach ( [ [ 'rowLimit' => 0 ], [ 'rowLimit' => 1001 ], [ 'rowLimit' => '5' ], [ 'surfaces' => [ 'unknown' ] ], [ 'dateFrom' => '2026-02-30' ], [ 'dateFrom' => '2026-10-08' ], [ 'prompt' => 'private' ] ] as $bad ) {
			$this->assertInstanceOf( \WP_Error::class, RecommendationFixtureExport::validate_selection( [ ...$this->selection(), ...$bad ] ) );
		}
	}

	public function test_utc_timestamp_selection_names_exact_bounds_without_exporting_times(): void {
		$selection = [
			...$this->selection(),
			'dateFrom' => '2026-10-07T12:00:01Z',
			'dateTo'   => '2026-10-07T23:59:59Z',
		];
		$this->assertSame( $selection, RecommendationFixtureExport::validate_selection( $selection ) );
		$this->assertSame(
			[
				'from' => '2026-10-07 12:00:01',
				'to'   => '2026-10-07 23:59:59',
			],
			RecommendationFixtureExport::selection_bounds( $selection )
		);
		$result = RecommendationFixtureExport::build( $this->entries(), $selection, $this->provenance() );
		$this->assertStringNotContainsString( '2026-10-07', json_encode( $result ) );
		foreach ( [ '2026-10-07T12:00:01-05:00', '2026-10-07T12:00:60Z', '2026-10-07T12:00:01.001Z', '2026-02-30T12:00:01Z' ] as $invalid ) {
			$this->assertInstanceOf(
				\WP_Error::class,
				RecommendationFixtureExport::validate_selection(
					[
						...$selection,
						'dateFrom' => $invalid,
					]
				)
			);
		}
	}

	public function test_old_capped_rankings_never_become_full_shown_evidence(): void {
		$entries = $this->entries();
		unset( $entries[0]['after']['outcome']['shownSuggestionKeys'] );
		$entries[0]['after']['outcome']['topSuggestionKeys'] = [ 's1', 's2', 's3' ];
		$entries[0]['after']['outcome']['resultCount']       = 7;
		$result = RecommendationFixtureExport::build( $entries, $this->selection(), $this->provenance() );
		$this->assertSame( 1, $result['sample']['shownSetCount'] );
		$this->assertSame( 0, $result['sample']['shownSuggestionCount'] );
		$this->assertSame( 1, $result['sample']['missingIdentityCount'] );
		$this->assertSame( 1, $result['coverage']['missingShownLinks'] );
		$this->assertArrayNotHasKey( 'suggestion', $result['rows'][0]['aliases'] );
		$this->assertSame( 'missing_links', $result['metrics'][1]['coverage'] );
	}

	public function test_multi_suggestion_shown_row_has_only_set_alias_and_full_sample_count(): void {
		$entries = $this->entries();
		$entries[0]['after']['outcome']['shownSuggestionKeys'] = [ 's1', 's2', 's3', 's4' ];
		$result = RecommendationFixtureExport::build( $entries, $this->selection(), $this->provenance() );
		$this->assertSame( 4, $result['sample']['shownSuggestionCount'] );
		$this->assertArrayNotHasKey( 'suggestion', $result['rows'][0]['aliases'] );
		$this->assertArrayHasKey( 'set', $result['rows'][0]['aliases'] );
		$this->assertCount( 2, $result['rows'] );
	}

	public function test_malformed_nested_types_fail_closed_without_coercion_or_exceptions(): void {
		$candidate = RecommendationFixtureExport::build( $this->entries(), $this->selection(), $this->provenance() );
		foreach ( [ 'surface', 'reason', 'rank', 'validationReason', 'patternTraits', 'version' ] as $key ) {
			$changed = $candidate;
			if ( 'surface' === $key ) {
				$changed['coverage']['surfaces'] = [ [ 'private' ] ]; } elseif ( 'reason' === $key ) {
				$changed['rows'][0]['reason'] = null; } elseif ( 'rank' === $key ) {
					$changed['rows'][0]['ranking']['rank'] = null; } elseif ( 'validationReason' === $key ) {
					$changed['rows'][0]['validationReason'] = [ 'private' ]; } elseif ( 'patternTraits' === $key ) {
							$changed['rows'][0]['patternTraits'] = [ [ 'private' ] ]; } else {
							$changed['provenance']['publicVersions']['gutenberg'] = null; }
							$this->assertFalse( RecommendationFixtureSchema::validate( $changed ), $key );
		}
	}

	public function test_bounded_repository_sample_declares_population_filters_order_and_cap(): void {
		WordPressTestState::$capabilities = [ 'manage_options' => true ];
		Repository::install();
		$base             = $this->entries()[0];
		$base['document'] = [
			'scopeKey' => 'post:5',
			'postId'   => 5,
			'postType' => 'post',
		];
		$base['target']   = [ 'clientId' => 'private-block' ];
		foreach ( [ [ 'older', '2026-10-01T00:00:00Z', 'block' ], [ 'newer', '2026-10-07T23:59:59Z', 'block' ], [ 'outside-period', '2026-09-30T23:59:59Z', 'block' ], [ 'outside-surface', '2026-10-07T23:59:59Z', 'template' ] ] as [ $id, $timestamp, $surface ] ) {
			$this->assertIsArray(
				Repository::create(
					[
						...$base,
						'id'        => $id,
						'timestamp' => $timestamp,
						'surface'   => $surface,
					]
				)
			);
		}
		$result = Repository::fixture_export_sample(
			[
				...$this->selection(),
				'rowLimit' => 1,
			]
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 2, $result['eligibleRowCount'] );
		$this->assertCount( 1, $result['entries'] );
		$this->assertSame( 'newer', $result['entries'][0]['id'] );
		$exact = Repository::fixture_export_sample(
			[
				...$this->selection(),
				'dateFrom' => '2026-10-07T12:00:01Z',
				'dateTo'   => '2026-10-07T23:59:59Z',
			]
		);
		$this->assertSame( 1, $exact['eligibleRowCount'] );
		WordPressTestState::$capabilities = [];
		$this->assertInstanceOf( \WP_Error::class, Repository::fixture_export_sample( $this->selection() ) );
	}

	private function selection(): array {
		return [
			'dateFrom' => '2026-10-01',
			'dateTo'   => '2026-10-07',
			'surfaces' => [ 'block' ],
			'rowLimit' => 500,
		];
	}

	private function provenance(): array {
		return [
			'kind'              => 'synthetic_fixture',
			'implementationSha' => str_repeat( 'a', 40 ),
			'publicVersions'    => [
				'plugin'               => '0.1.1',
				'wordpress'            => '7.1.3',
				'ranking'              => 'contextual-ranking-v1',
				'validationVocabulary' => 'validation-reasons-v1',
				'report'               => 'governance-learning-report-v1',
			],
			'config'            => [
				'surfaces'    => [ 'block' ],
				'rankingMode' => 'static',
			],
		];
	}

	private function entries(): array {
		return [
			[
				'id'            => 'activity-secret-shown',
				'schemaVersion' => 1,
				'type'          => 'recommendation_outcome',
				'surface'       => 'block',
				'timestamp'     => '2026-10-07T12:00:00Z',
				'userId'        => 7,
				'after'         => [
					'outcome' => [
						'event'                  => 'shown',
						'recommendationSetId'    => 'set-secret',
						'sourceRequestSignature' => 'hash_private',
						'shownSuggestionKeys'    => [ 'suggestion-secret' ],
						'resultCount'            => 1,
						'prompt'                 => 'Private prompt',
					],
				],
				'request'       => [ 'trace' => [ 'secret' => 'sk-secret' ] ],
				'suggestion'    => 'Private label',
			],
			[
				'id'              => 'activity-secret-apply',
				'schemaVersion'   => 1,
				'type'            => 'apply_suggestion',
				'surface'         => 'block',
				'timestamp'       => '2026-10-07T12:01:00Z',
				'executionResult' => 'applied',
				'suggestionKey'   => 'suggestion-secret',
				'request'         => [
					'url'            => 'https://example.test/private',
					'recommendation' => [
						'recommendationSetId' => 'set-secret',
						'suggestionKey'       => 'suggestion-secret',
						'ranking'             => [
							'contextScore'    => 0.7,
							'blendedScore'    => 0.6,
							'contextEvidence' => [ 'prompt_match' => 0.8 ],
						],
						'learningAttribution' => [
							'generationId'     => 'generation-secret',
							'provider'         => 'provider-private',
							'model'            => 'model-private',
							'guidelineVersion' => 'hash_private',
						],
					],
				],
				'after'           => [ 'operations' => [ [ 'content' => 'Private generated text' ] ] ],
				'undo'            => [ 'status' => 'available' ],
			],
		];
	}
}
