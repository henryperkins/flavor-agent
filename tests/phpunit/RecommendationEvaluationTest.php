<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\LLM\NavigationPrompt;
use FlavorAgent\LLM\Prompt;
use FlavorAgent\LLM\StylePrompt;
use FlavorAgent\LLM\TemplatePartPrompt;
use FlavorAgent\LLM\TemplatePrompt;
use PHPUnit\Framework\TestCase;

final class RecommendationEvaluationTest extends TestCase {

	public function test_phase_zero_baseline_metrics_match_recorded_fixture_output(): void {
		$fixtures = require __DIR__ . '/fixtures/recommendation-evaluation-fixtures.php';
		$baseline = json_decode(
			(string) file_get_contents( __DIR__ . '/fixtures/recommendation-evaluation-baseline.json' ),
			true
		);

		$this->assertIsArray( $baseline );
		$this->assertSame(
			self::normalize_expected_metrics( $baseline ),
			self::round_metric_values( self::evaluate( $fixtures ) )
		);
	}

	public function test_phase_zero_parser_backed_metrics_match_recorded_fixture_output(): void {
		$fixtures = require __DIR__ . '/fixtures/recommendation-evaluation-parser-fixtures.php';

		foreach ( $fixtures as $fixture ) {
			$this->assertIsArray( $fixture );
			$expected = $fixture['expectedMetrics'] ?? null;
			$this->assertIsArray( $expected );

			$materialized = self::materialize_parser_fixture( $fixture );

			$this->assertSame(
				self::normalize_expected_metrics( $expected ),
				self::round_metric_values( self::evaluate( [ $materialized ] ) )
			);

			$expected_top_ranked = $fixture['expectedTopRankedMetrics'] ?? null;
			if ( ! empty( $fixture['rankedMetricProbe'] ) ) {
				$this->assertIsArray( $expected_top_ranked, 'Ranked parser probes must record top-ranked Phase 0 metrics.' );
			}

			if ( is_array( $expected_top_ranked ) ) {
				$this->assertSame(
					self::normalize_expected_metrics( $expected_top_ranked ),
					self::round_metric_values( self::evaluate( [ self::top_ranked_fixture( $materialized ) ] ) )
				);
			}
		}
	}

	public function test_phase_two_parser_backed_metrics_include_design_semantic_prompt_wiring(): void {
		$fixtures              = require __DIR__ . '/fixtures/recommendation-evaluation-phase-2-parser-fixtures.php';
		$materialized_fixtures = [];

		foreach ( $fixtures as $name => $fixture ) {
			$this->assertIsArray( $fixture );
			self::assert_phase_two_prompt_includes_design_semantics(
				is_string( $name ) ? $name : 'phase_2_fixture',
				$fixture
			);

			$expected = $fixture['expectedMetrics'] ?? null;
			$this->assertIsArray( $expected );

			$materialized = self::materialize_parser_fixture( $fixture );

			$this->assertSame(
				self::normalize_expected_metrics( $expected ),
				self::round_metric_values( self::evaluate( [ $materialized ] ) )
			);

			$expected_top_ranked = $fixture['expectedTopRankedMetrics'] ?? null;
			if ( ! empty( $fixture['rankedMetricProbe'] ) ) {
				$this->assertIsArray( $expected_top_ranked, 'Ranked parser probes must record top-ranked Phase 2 metrics.' );
			}

			if ( is_array( $expected_top_ranked ) ) {
				$this->assertSame(
					self::normalize_expected_metrics( $expected_top_ranked ),
					self::round_metric_values( self::evaluate( [ self::top_ranked_fixture( $materialized ) ] ) )
				);
			}

			$materialized_fixtures[] = $materialized;
		}

		$metrics = self::round_metric_values( self::evaluate( $materialized_fixtures ) );

		$this->assertSame( 0.0, $metrics['invalidOperationRate'] );
		$this->assertSame( 0.0, $metrics['noiseRate'] );

		$baseline = json_decode(
			(string) file_get_contents( __DIR__ . '/fixtures/recommendation-evaluation-baseline.json' ),
			true
		);
		$this->assertIsArray( $baseline );

		$baseline = self::normalize_expected_metrics( $baseline );

		$this->assertSame( 0.3333, $baseline['invalidOperationRate'] );
		$this->assertSame( 1.0, $baseline['noiseRate'] );
	}

	/**
	 * @param array<string, mixed> $fixture
	 */
	private static function assert_phase_two_prompt_includes_design_semantics( string $name, array $fixture ): void {
		$parser  = is_string( $fixture['parser'] ?? null ) ? $fixture['parser'] : '';
		$context = is_array( $fixture['context'] ?? null ) ? $fixture['context'] : [];

		self::assertIsArray(
			$context['designSemantics'] ?? null,
			"{$name} must include designSemantics context."
		);

		$prompt = match ( $parser ) {
			'block'         => Prompt::build_user( $context, '' ),
			'template'      => TemplatePrompt::build_user( $context, '' ),
			'template_part' => TemplatePartPrompt::build_user( $context, '' ),
			default         => self::fail( "Unsupported Phase 2 prompt fixture: {$parser}" ),
		};

		self::assertStringContainsString( '## Design semantic context', $prompt, $name );

		if ( is_string( $context['designSemantics']['sectionRole'] ?? null ) ) {
			self::assertStringContainsString(
				'Role: ' . $context['designSemantics']['sectionRole'],
				$prompt,
				$name
			);
		}

		if ( ! empty( $context['designSemantics']['negativeSignals'] ) ) {
			self::assertStringContainsString( 'Negative signals:', $prompt, $name );
		}
	}

	/**
	 * @param array<string, mixed> $fixture
	 * @return array<string, mixed>
	 */
	private static function materialize_parser_fixture( array $fixture ): array {
		$parser   = is_string( $fixture['parser'] ?? null ) ? $fixture['parser'] : '';
		$response = is_array( $fixture['response'] ?? null ) ? $fixture['response'] : [];
		$context  = is_array( $fixture['context'] ?? null ) ? $fixture['context'] : [];

		$parsed = self::parse_fixture_response( $parser, $response, $context );
		self::assertIsArray( $parsed );

		$suggestions = self::extract_parser_suggestions( $parser, $fixture, $parsed );
		$suggestions = self::annotate_parser_rejections_from_response( $suggestions, $response );

		$materialized = [
			'surface'     => is_string( $fixture['surface'] ?? null ) ? $fixture['surface'] : $parser,
			'alreadyGood' => ! empty( $fixture['alreadyGood'] ),
			'suggestions' => $suggestions,
		];

		if ( is_array( $fixture['currentState'] ?? null ) ) {
			$materialized['currentState'] = $fixture['currentState'];
		}

		return $materialized;
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function parse_fixture_response( string $parser, array $response, array $context ): array {
		$raw = wp_json_encode( $response );
		self::assertIsString( $raw );

		$parsed = match ( $parser ) {
			'block'         => Prompt::parse_response( $raw ),
			'style'         => StylePrompt::parse_response( $raw, $context ),
			'template'      => TemplatePrompt::parse_response( $raw, $context ),
			'template_part' => TemplatePartPrompt::parse_response( $raw, $context ),
			'navigation'    => NavigationPrompt::parse_response( $raw, $context ),
			default         => self::fail( "Unsupported parser fixture: {$parser}" ),
		};

		self::assertIsArray( $parsed );

		return $parsed;
	}

	/**
	 * @param array<string, mixed> $fixture
	 * @param array<string, mixed> $parsed
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_parser_suggestions( string $parser, array $fixture, array $parsed ): array {
		if ( 'block' === $parser ) {
			$lane = is_string( $fixture['lane'] ?? null ) ? $fixture['lane'] : 'block';
			return is_array( $parsed[ $lane ] ?? null ) ? $parsed[ $lane ] : [];
		}

		return is_array( $parsed['suggestions'] ?? null ) ? $parsed['suggestions'] : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $suggestions
	 * @param array<string, mixed> $response
	 * @return array<int, array<string, mixed>>
	 */
	private static function annotate_parser_rejections_from_response( array $suggestions, array $response ): array {
		$raw_suggestions = is_array( $response['suggestions'] ?? null )
			? $response['suggestions']
			: ( is_array( $response['block'] ?? null ) ? $response['block'] : [] );

		$raw_operation_counts = [];
		foreach ( $raw_suggestions as $raw_suggestion ) {
			if ( ! is_array( $raw_suggestion ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $raw_suggestion['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$raw_operation_counts[ $label ] = count(
				is_array( $raw_suggestion['operations'] ?? null ) ? $raw_suggestion['operations'] : []
			);
		}

		foreach ( $suggestions as &$suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$label               = sanitize_text_field( (string) ( $suggestion['label'] ?? '' ) );
			$raw_operation_count = $raw_operation_counts[ $label ] ?? 0;
			$parsed_operations   = is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [];
			$rejected_count      = max( 0, $raw_operation_count - count( $parsed_operations ) );

			if ( $rejected_count > 0 ) {
				$suggestion['rejectedOperations'] = array_fill(
					0,
					$rejected_count,
					[
						'reason' => 'parser validation dropped operation',
					]
				);
			}
		}
		unset( $suggestion );

		return $suggestions;
	}

	/**
	 * @param array<string, mixed> $fixture
	 * @return array<string, mixed>
	 */
	private static function top_ranked_fixture( array $fixture ): array {
		$suggestions = is_array( $fixture['suggestions'] ?? null ) ? $fixture['suggestions'] : [];

		return array_merge(
			$fixture,
			[
				'suggestions' => array_slice( $suggestions, 0, 1 ),
			]
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $fixtures
	 * @return array<string, float|int>
	 */
	private static function evaluate( array $fixtures ): array {
		$total_suggestions             = 0;
		$operation_count               = 0;
		$rejected_operation_count      = 0;
		$preset_candidates             = 0;
		$preset_backed                 = 0;
		$no_op_count                   = 0;
		$already_good_suggestion_count = 0;
		$already_good_noise_count      = 0;

		foreach ( $fixtures as $fixture ) {
			$suggestions        = is_array( $fixture['suggestions'] ?? null ) ? $fixture['suggestions'] : [];
			$total_suggestions += count( $suggestions );

			if ( ! empty( $fixture['alreadyGood'] ) ) {
				$already_good_suggestion_count += count( $suggestions );
				$already_good_noise_count      += count( $suggestions );
			}

			foreach ( $suggestions as $suggestion ) {
				if ( ! is_array( $suggestion ) ) {
					continue;
				}

				$operations                = is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [];
				$rejected                  = is_array( $suggestion['rejectedOperations'] ?? null ) ? $suggestion['rejectedOperations'] : [];
				$operation_count          += count( $operations );
				$rejected_operation_count += count( $rejected );

				foreach ( $operations as $operation ) {
					if ( ! is_array( $operation ) ) {
						continue;
					}

					if ( self::is_preset_candidate_operation( $operation ) ) {
						++$preset_candidates;
						if ( self::is_preset_backed_operation( $operation ) ) {
							++$preset_backed;
						}
					}
				}

				if ( self::is_no_op_suggestion( $suggestion, $fixture ) ) {
					++$no_op_count;
				}
			}
		}

		return [
			'fixtures'             => count( $fixtures ),
			'suggestions'          => $total_suggestions,
			'invalidOperationRate' => self::rate( $rejected_operation_count, $operation_count + $rejected_operation_count ),
			'presetAdherenceRate'  => self::rate( $preset_backed, $preset_candidates ),
			'noOpRate'             => self::rate( $no_op_count, $total_suggestions ),
			'noiseRate'            => self::rate( $already_good_noise_count, $already_good_suggestion_count ),
		];
	}

	/**
	 * @param array<string, mixed> $operation
	 */
	private static function is_preset_candidate_operation( array $operation ): bool {
		$path = is_array( $operation['path'] ?? null ) ? implode( '.', $operation['path'] ) : '';

		return str_contains( $path, 'color' )
			|| str_contains( $path, 'spacing' )
			|| str_contains( $path, 'typography' )
			|| array_key_exists( 'valueType', $operation );
	}

	/**
	 * @param array<string, mixed> $operation
	 */
	private static function is_preset_backed_operation( array $operation ): bool {
		$value_type = is_string( $operation['valueType'] ?? null ) ? $operation['valueType'] : '';
		$value      = is_string( $operation['value'] ?? null ) ? $operation['value'] : '';

		return 'preset' === $value_type
			|| str_starts_with( $value, 'var:preset|' )
			|| str_starts_with( $value, 'var(--wp--preset--' );
	}

	/**
	 * @param array<string, mixed> $suggestion
	 * @param array<string, mixed> $fixture
	 */
	private static function is_no_op_suggestion( array $suggestion, array $fixture ): bool {
		$updates = $suggestion['attributeUpdates'] ?? null;
		$current = $fixture['currentState']['attributes'] ?? null;

		return is_array( $updates ) && is_array( $current ) && $updates === $current;
	}

	private static function rate( int $numerator, int $denominator ): float {
		if ( 0 === $denominator ) {
			return 0.0;
		}

		return $numerator / $denominator;
	}

	/**
	 * JSON decoding returns whole-number rates as ints. Normalize the expected
	 * metric shape so strict comparisons still catch value changes without
	 * failing on int/float representation differences.
	 *
	 * @param array<string, mixed> $metrics
	 * @return array<string, float|int>
	 */
	private static function normalize_expected_metrics( array $metrics ): array {
		foreach ( [ 'invalidOperationRate', 'presetAdherenceRate', 'noOpRate', 'noiseRate' ] as $key ) {
			if ( array_key_exists( $key, $metrics ) && is_numeric( $metrics[ $key ] ) ) {
				$metrics[ $key ] = round( (float) $metrics[ $key ], 4 );
			}
		}

		return $metrics;
	}

	/**
	 * @param array<string, float|int> $metrics
	 * @return array<string, float|int>
	 */
	private static function round_metric_values( array $metrics ): array {
		foreach ( $metrics as $key => $value ) {
			if ( is_float( $value ) ) {
				$metrics[ $key ] = round( $value, 4 );
			}
		}

		return $metrics;
	}
}
