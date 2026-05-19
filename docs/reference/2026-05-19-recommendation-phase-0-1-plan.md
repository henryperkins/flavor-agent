# Recommendation Phase 0/1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the fixture-backed recommendation measurement stub from Phase 0 and the ranking/schema hardening slice from Phase 1 in `improving-levers.md`.

**Architecture:** Phase 0 creates a deterministic, provider-free PHPUnit harness that computes baseline recommendation-quality metrics from static fixtures and at least one parser-backed fixture before any ranking behavior changes. Phase 1 adds structured `ranking` objects to strict LLM response schemas, updates the matching prompt examples/rules so strict providers are asked for the newly required nullable field, and changes parser ranking from "first numeric score/confidence wins, otherwise deterministic fallback" to "always blend model and deterministic components," while keeping `RankingContract::derive_score()` as the deterministic-component seam.

**Tech Stack:** WordPress plugin PHP, PHPUnit, `FlavorAgent\Support\RankingContract`, strict JSON schemas in `FlavorAgent\LLM\ResponseSchema`, parser tests for block/style/template/template-part/navigation recommendation surfaces, `npm run check:docs`.

---

## Scope

This plan covers only:

- Phase 0 measurement stub.
- Phase 1 ranking and strict LLM schema hardening.
- No design-semantics collection, guideline fingerprints, docs-fingerprint split, pattern component scores, UI work, browser tests, or provider calls.

The fixture set is intentionally a minimum Phase 0 stub, not the full fixture matrix sketched in `improving-levers.md`. Later phases should expand the matrix before claiming broader recommendation-quality movement.

The implementation must leave unrelated dirty settings work alone.

## Files

Create:

- `tests/phpunit/fixtures/recommendation-evaluation-fixtures.php` - provider-free static fixture payloads for baseline metrics.
- `tests/phpunit/fixtures/recommendation-evaluation-parser-fixtures.php` - provider-free raw parser payloads plus expected parser-backed baseline metrics.
- `tests/phpunit/fixtures/recommendation-evaluation-baseline.json` - recorded Phase 0 baseline metrics.
- `tests/phpunit/RecommendationEvaluationTest.php` - metric harness and baseline assertions.

Modify:

- `inc/Support/RankingContract.php` - add composite scoring helper and preserve optional structured ranking context fields while preserving existing normalization behavior.
- `inc/LLM/ResponseSchema.php` - add nullable `ranking` objects to strict LLM suggestion schemas.
- `inc/LLM/Prompt.php` - block prompt shape includes nullable `ranking`, then the parser uses blended scores and strips model score/confidence before normalizing final ranking metadata.
- `inc/LLM/StylePrompt.php` - style prompt shape includes nullable `ranking`, then the parser uses blended scores and strips model score/confidence before normalizing final ranking metadata.
- `inc/LLM/TemplatePrompt.php` - template prompt shape includes nullable `ranking`, then the parser uses blended scores and strips model score/confidence before normalizing final ranking metadata.
- `inc/LLM/TemplatePartPrompt.php` - template-part prompt shape includes nullable `ranking`, then the parser uses blended scores and strips model score/confidence before normalizing final ranking metadata.
- `inc/LLM/NavigationPrompt.php` - navigation prompt shape includes nullable `ranking`, then the parser uses blended scores and strips model score/confidence before normalizing final ranking metadata.
- `tests/phpunit/RankingContractTest.php` - cover composite score weighting, missing components, clamping, and deterministic-only behavior.
- `tests/phpunit/ResponseSchemaTest.php` - cover strict schema `ranking` object support.
- `tests/phpunit/PromptRulesTest.php` - update block ranking parser expectations from first-score wins to blended scoring.
- `tests/phpunit/StylePromptTest.php` - update style ranking parser expectations from first-score wins to blended scoring.
- `tests/phpunit/TemplatePromptTest.php` - update template ranking parser expectations from first-score wins to blended scoring.
- `tests/phpunit/TemplatePartPromptTest.php` - update template-part ranking parser expectations from first-score wins to blended scoring.
- `tests/phpunit/NavigationAbilitiesTest.php` - update navigation ranking parser expectations from first-score wins to blended scoring.
- `improving-levers.md` - only if implementation changes the Phase 0/1 contract defined there.

Do not edit generated `build/` or `dist/` artifacts.

## Phase 0 Acceptance Metrics

Phase 0 must emit and assert this baseline shape:

```json
{
  "fixtures": 5,
  "suggestions": 6,
  "invalidOperationRate": 0.3333,
  "presetAdherenceRate": 0.5,
  "noOpRate": 0.1667,
  "noiseRate": 1
}
```

The embedded static fixtures produce this baseline from 5 fixtures, 6 suggestions, 4 accepted operations, 2 rejected operations, 2 preset candidates, 1 preset-backed operation, 1 no-op suggestion, and 1 suggestion attached to an already-good fixture. Phase 0 must also include a parser-backed fixture that calls a real parser and feeds the parsed result into the same `evaluate()` metric function before Task 3 starts. The parser-backed fixture may have its own small baseline assertion, but it must not be optional or deferred until after Phase 1; otherwise the static baseline can pass while parser output regresses. The exact fixture count and values may change during implementation only when the fixture data changes in the same patch and the test explains why. Do not tune metrics after Phase 1 to make results look better; Phase 0 is the baseline.

Phase 1 must add a separate ranked-parser metric gate before any ranking code changes. The static all-suggestion baseline above is expected to stay unchanged because ranking alone should not add or remove suggestions. Ranking movement must be measured on parser-backed fixtures by evaluating both all parsed suggestions and the top-ranked parsed suggestion. Before Task 3 starts, record the Phase 0 top-ranked baseline for those parser fixtures; after Task 6, Task 7 must prove:

- Static all-suggestion metrics still match `recommendation-evaluation-baseline.json`.
- Parser all-suggestion metrics stay stable unless a parser validation change deliberately changes the suggestion set and explains that change in the test.
- Parser top-ranked metrics move in the expected direction: `invalidOperationRate` does not increase, `presetAdherenceRate` stays flat or improves, and at least one current-state fixture lowers `noOpRate` by moving a real change above a high-confidence no-op.

The parser fixture matrix must include raw payloads that make those ranked metrics meaningful before Phase 1 changes: a high-confidence no-op versus a lower-confidence real update, a high-confidence raw/freeform style value versus a lower-confidence preset-backed operation, and a high-confidence invalid or downgraded operation versus a lower-confidence valid operation. Each fixture must call a live parser and carry the current state or parser context needed to evaluate the metric; do not replace the ranked gate with hard-coded normalized suggestions.

## Task 1: Create Phase 0 Fixtures

**Files:**
- Create: `tests/phpunit/fixtures/recommendation-evaluation-fixtures.php`
- Create: `tests/phpunit/fixtures/recommendation-evaluation-parser-fixtures.php`

- [ ] **Step 1: Add the fixture file**

Create the fixture directory first because `tests/phpunit/fixtures/` does not exist in the current checkout:

```bash
mkdir -p tests/phpunit/fixtures
```

Create `tests/phpunit/fixtures/recommendation-evaluation-fixtures.php` with this structure:

```php
<?php

declare(strict_types=1);

return [
	'already_good_block_with_noise' => [
		'surface'      => 'block',
		'alreadyGood'  => true,
		'currentState' => [
			'attributes' => [
				'style' => [
					'color' => [
						'text' => 'var(--wp--preset--color--contrast)',
					],
				],
			],
		],
		'suggestions'  => [
			[
				'label'            => 'Keep contrast text',
				'attributeUpdates' => [
					'style' => [
						'color' => [
							'text' => 'var(--wp--preset--color--contrast)',
						],
					],
				],
				'operations'       => [],
				'ranking'          => [
					'score' => 0.68,
				],
			],
		],
	],
	'block_with_invalid_and_valid_operations' => [
		'surface'     => 'block',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'              => 'Replace with hero pattern',
				'operations'         => [
					[
						'type'        => 'replace_block_with_pattern',
						'patternName' => 'theme/hero',
					],
				],
				'rejectedOperations' => [
					[
						'type'   => 'replace_block_with_pattern',
						'reason' => 'target path no longer matches',
					],
				],
			],
			[
				'label'              => 'Insert CTA nearby',
				'operations'         => [
					[
						'type'        => 'insert_pattern',
						'patternName' => 'theme/cta',
					],
				],
				'rejectedOperations' => [
					[
						'type'   => 'insert_pattern',
						'reason' => 'insertion target is locked',
					],
				],
			],
		],
	],
	'style_with_theme_preset' => [
		'surface'     => 'style',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'      => 'Use accent preset',
				'operations' => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|accent',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'accent',
					],
				],
			],
		],
	],
	'style_with_raw_value' => [
		'surface'     => 'style',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'      => 'Use raw brand color',
				'operations' => [
					[
						'type'      => 'set_styles',
						'path'      => [ 'color', 'background' ],
						'value'     => '#123456',
						'valueType' => 'raw',
					],
				],
			],
		],
	],
	'template_with_pattern_suggestion' => [
		'surface'     => 'template',
		'alreadyGood' => false,
		'suggestions' => [
			[
				'label'              => 'Add footer utility pattern',
				'patternSuggestions' => [ 'theme/footer-utility' ],
				'operations'         => [],
			],
		],
	],
];
```

- [ ] **Step 2: Verify fixture loads**

Run:

```bash
php -r '$fixtures = require "tests/phpunit/fixtures/recommendation-evaluation-fixtures.php"; echo count($fixtures), PHP_EOL;'
```

Expected: prints `5`.

- [ ] **Step 3: Add parser-backed fixture file**

Create `tests/phpunit/fixtures/recommendation-evaluation-parser-fixtures.php` with raw parser payloads. This fixture is deliberately separate from the static fixture file so the static Phase 0 baseline remains stable while parser output is still covered before Phase 1 changes ranking.

The parser-backed file must contain at least these ranked metric probes:

- A no-op probe where a high-confidence suggestion repeats the current state and a lower-confidence suggestion makes a real change. This fixture must include `currentState` so `evaluate()` can count the pre-Phase 1 top-ranked suggestion as a no-op.
- A preset probe where a high-confidence raw/freeform style operation competes with a lower-confidence preset-backed operation. This fixture must include the style parser context needed for the live parser to validate both operations.
- An invalid-operation probe where a high-confidence invalid or downgraded operation competes with a lower-confidence valid operation. This fixture must use the relevant live parser context so parser validation, not hand-authored normalized data, determines the final parsed suggestions.

Keep the existing block parser smoke fixture as the smallest parser-materialization example:

```php
<?php

declare(strict_types=1);

return [
	'block_parser_executable_update' => [
		'surface'         => 'block',
		'alreadyGood'     => false,
		'parser'          => 'block',
		'response'        => [
			'block' => [
				[
					'label'            => 'Use correct heading level',
					'description'      => 'Use a heading level that fits this section.',
					'type'             => 'attribute_change',
					'panel'            => 'general',
					'attributeUpdates' => '{"level":2}',
					'operations'       => [],
					'confidence'       => 0.55,
				],
			],
		],
		'expectedMetrics' => [
			'fixtures'             => 1,
			'suggestions'          => 1,
			'invalidOperationRate' => 0.0,
			'presetAdherenceRate'  => 0.0,
			'noOpRate'             => 0.0,
			'noiseRate'            => 0.0,
		],
	],
];
```

The parser fixture must not hard-code normalized suggestions. The test added in Task 2 must call the live parser, transform its parsed output into the same fixture shape consumed by `evaluate()`, and assert the parser-backed metrics through that same evaluator. Parser fixture entries may declare `expectedMetrics` for all parsed suggestions and `expectedTopRankedMetrics` for the first parsed suggestion after parser sorting. Both expectations are recorded before Phase 1 ranking changes.

## Task 2: Add Phase 0 Metric Harness

**Files:**
- Create: `tests/phpunit/RecommendationEvaluationTest.php`
- Create: `tests/phpunit/fixtures/recommendation-evaluation-baseline.json`

- [ ] **Step 1: Write the failing PHPUnit test**

Create `tests/phpunit/RecommendationEvaluationTest.php`:

```php
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

	/**
	 * @param array<string, mixed> $fixture
	 * @return array<string, mixed>
	 */
	private static function materialize_parser_fixture( array $fixture ): array {
		$parser = is_string( $fixture['parser'] ?? null ) ? $fixture['parser'] : '';
		$response = is_array( $fixture['response'] ?? null ) ? $fixture['response'] : [];
		$context = is_array( $fixture['context'] ?? null ) ? $fixture['context'] : [];

		$parsed = self::parse_fixture_response( $parser, $response, $context );
		self::assertIsArray( $parsed );

		$suggestions = self::extract_parser_suggestions( $parser, $fixture, $parsed );

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
		$total_suggestions = 0;
		$operation_count = 0;
		$rejected_operation_count = 0;
		$preset_candidates = 0;
		$preset_backed = 0;
		$no_op_count = 0;
		$already_good_suggestion_count = 0;
		$already_good_noise_count = 0;

		foreach ( $fixtures as $fixture ) {
			$suggestions = is_array( $fixture['suggestions'] ?? null ) ? $fixture['suggestions'] : [];
			$total_suggestions += count( $suggestions );

			if ( ! empty( $fixture['alreadyGood'] ) ) {
				$already_good_suggestion_count += count( $suggestions );
				$already_good_noise_count += count( $suggestions );
			}

			foreach ( $suggestions as $suggestion ) {
				if ( ! is_array( $suggestion ) ) {
					continue;
				}

				$operations = is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [];
				$rejected = is_array( $suggestion['rejectedOperations'] ?? null ) ? $suggestion['rejectedOperations'] : [];
				$operation_count += count( $operations );
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
		$value = is_string( $operation['value'] ?? null ) ? $operation['value'] : '';

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
```

The test must normalize expected metric rate values before strict comparison. JSON whole-number rates like `1` decode as ints while `evaluate()` returns floats like `1.0`; without `normalize_expected_metrics()`, the baseline can fail because of PHP type identity rather than metric drift. Ranked parser probes must set `rankedMetricProbe` and `expectedTopRankedMetrics`; otherwise Task 7 can pass while Phase 1 only preserves all-suggestion counts.

- [ ] **Step 2: Run the test and verify it fails because the baseline file is missing**

Run:

```bash
composer run test:php -- --filter RecommendationEvaluationTest
```

Expected: FAIL because `recommendation-evaluation-baseline.json` does not exist or cannot be decoded. The parser-backed test should already execute the live parser fixtures and fail independently if any parser fixture cannot be materialized or any ranked probe lacks `expectedTopRankedMetrics`.

- [ ] **Step 3: Add the baseline file**

Create `tests/phpunit/fixtures/recommendation-evaluation-baseline.json`:

```json
{
  "fixtures": 5,
  "suggestions": 6,
  "invalidOperationRate": 0.3333,
  "presetAdherenceRate": 0.5,
  "noOpRate": 0.1667,
  "noiseRate": 1
}
```

- [ ] **Step 4: Run the Phase 0 test**

Run:

```bash
composer run test:php -- --filter RecommendationEvaluationTest
```

Expected: PASS for the static baseline assertion, parser-backed all-suggestion assertions, and ranked parser top-suggestion assertions.

- [ ] **Step 5: Commit checkpoint if the operator wants commits**

```bash
git add tests/phpunit/fixtures/recommendation-evaluation-fixtures.php tests/phpunit/fixtures/recommendation-evaluation-parser-fixtures.php tests/phpunit/fixtures/recommendation-evaluation-baseline.json tests/phpunit/RecommendationEvaluationTest.php
git commit -m "Add recommendation evaluation baseline harness"
```

## Task 3: Add Composite Ranking Helper

**Files:**
- Modify: `inc/Support/RankingContract.php`
- Modify: `tests/phpunit/RankingContractTest.php`

- [ ] **Step 1: Add failing tests for blended scores**

Append these tests to `tests/phpunit/RankingContractTest.php`:

```php
public function test_blend_score_weights_model_and_deterministic_components(): void {
	$result = RankingContract::blend_score(
		[
			'model'         => 0.55,
			'deterministic' => 0.9,
			'context'       => null,
		]
	);

	$this->assertSame( 0.76, $result );
}

public function test_blend_score_renormalizes_when_model_is_missing(): void {
	$result = RankingContract::blend_score(
		[
			'model'         => null,
			'deterministic' => 0.7,
			'context'       => null,
		]
	);

	$this->assertSame( 0.7, $result );
}

public function test_blend_score_clamps_components(): void {
	$result = RankingContract::blend_score(
		[
			'model'         => 2,
			'deterministic' => -1,
			'context'       => 0.5,
		]
	);

	$this->assertSame( 0.425, $result );
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run:

```bash
composer run test:php -- --filter RankingContractTest
```

Expected: FAIL with `Call to undefined method FlavorAgent\Support\RankingContract::blend_score()`.

- [ ] **Step 3: Implement `RankingContract::blend_score()`**

Add this method to `inc/Support/RankingContract.php` after `derive_score()`:

```php
/**
 * @param array{model?: mixed, deterministic?: mixed, context?: mixed} $components
 * @param array{model?: float, deterministic?: float, context?: float} $weights
 */
public static function blend_score( array $components, array $weights = [] ): float {
	$weights = array_merge(
		[
			'model'         => 0.30,
			'deterministic' => 0.45,
			'context'       => 0.25,
		],
		$weights
	);

	$weighted_score = 0.0;
	$total_weight   = 0.0;

	foreach ( [ 'model', 'deterministic', 'context' ] as $component ) {
		$value = $components[ $component ] ?? null;
		$weight = (float) ( $weights[ $component ] ?? 0.0 );

		if ( $weight <= 0.0 || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			continue;
		}

		$weighted_score += self::coerce_score( $value ) * $weight;
		$total_weight   += $weight;
	}

	if ( $total_weight <= 0.0 ) {
		return 0.0;
	}

	return self::coerce_score( round( $weighted_score / $total_weight, 4 ) );
}
```

- [ ] **Step 4: Run the focused tests**

Run:

```bash
composer run test:php -- --filter RankingContractTest
```

Expected: PASS.

## Task 4: Add Ranking Objects To Strict LLM Schemas

**Files:**
- Modify: `inc/LLM/ResponseSchema.php`
- Modify: `inc/LLM/Prompt.php`
- Modify: `inc/LLM/StylePrompt.php`
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Modify: `inc/LLM/NavigationPrompt.php`
- Modify: `inc/Support/RankingContract.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `tests/phpunit/ResponseSchemaTest.php`
- Modify: `tests/phpunit/RankingContractTest.php`
- Modify: `tests/phpunit/RegistrationTest.php`
- Modify: `tests/phpunit/PromptRulesTest.php`
- Modify: `tests/phpunit/StylePromptTest.php`
- Modify: `tests/phpunit/TemplatePromptTest.php`
- Modify: `tests/phpunit/TemplatePartPromptTest.php`
- Modify: `tests/phpunit/NavigationAbilitiesTest.php`

- [ ] **Step 1: Add failing schema and normalization tests**

Add this test to `tests/phpunit/ResponseSchemaTest.php`:

```php
public function test_strict_llm_schemas_accept_nullable_ranking_objects(): void {
	$cases = [
		'template'      => [ 'suggestions' ],
		'template_part' => [ 'suggestions' ],
		'style'         => [ 'suggestions' ],
		'navigation'    => [ 'suggestions' ],
	];

	foreach ( $cases as $surface => [ $list_key ] ) {
		$schema = ResponseSchema::get( $surface );
		$ranking = $schema['properties'][ $list_key ]['items']['properties']['ranking'] ?? null;

		$this->assertIsArray( $ranking, "{$surface} suggestion items should declare ranking." );
		$this->assertSame( [ 'object', 'null' ], $ranking['type'] ?? null );
		$this->assertFalse( (bool) ( $ranking['additionalProperties'] ?? true ) );
		$this->assertSame( [ 'number', 'null' ], $ranking['properties']['score']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $ranking['properties']['reason']['type'] ?? null );
		$this->assertSame( [ 'array', 'null' ], $ranking['properties']['sourceSignals']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $ranking['properties']['designPrinciple']['type'] ?? null );
		$this->assertSame( [ 'string', 'null' ], $ranking['properties']['risk']['type'] ?? null );
		$this->assertSame(
			[ 'score', 'reason', 'sourceSignals', 'designPrinciple', 'risk' ],
			$ranking['required'] ?? null
		);
	}
}

public function test_block_schema_accepts_nullable_ranking_objects_on_all_lanes(): void {
	$schema = ResponseSchema::get( 'block' );

	foreach ( [ 'settings', 'styles', 'block' ] as $list_key ) {
		$ranking = $schema['properties'][ $list_key ]['items']['properties']['ranking'] ?? null;

		$this->assertIsArray( $ranking, "Block {$list_key} items should declare ranking." );
		$this->assertSame( [ 'object', 'null' ], $ranking['type'] ?? null );
		$this->assertSame( [ 'number', 'null' ], $ranking['properties']['score']['type'] ?? null );
		$this->assertSame(
			[ 'score', 'reason', 'sourceSignals', 'designPrinciple', 'risk' ],
			$ranking['required'] ?? null
		);
	}
}
```

Add this test to `tests/phpunit/RankingContractTest.php`:

```php
public function test_normalize_preserves_optional_ranking_context_fields(): void {
	$result = RankingContract::normalize(
		[
			'score'           => 0.5,
			'designPrinciple' => 'Improve hierarchy',
			'risk'            => 'Avoid layout shift',
		],
		[]
	);

	$this->assertSame( 'Improve hierarchy', $result['designPrinciple'] );
	$this->assertSame( 'Avoid layout shift', $result['risk'] );
}

public function test_normalize_merges_model_and_deterministic_source_signals_without_dropping_plugin_signals(): void {
	$result = RankingContract::normalize(
		[
			'sourceSignals' => [ 'model_reason' ],
		],
		[
			'sourceSignals' => [ 'llm_response', 'block_surface', 'has_executable_updates' ],
		]
	);

	$this->assertSame(
		[ 'llm_response', 'block_surface', 'has_executable_updates', 'model_reason' ],
		$result['sourceSignals']
	);
}
```

Update the existing ability registration schema coverage in `tests/phpunit/RegistrationTest.php`:

```php
// In test_register_abilities_exposes_ranking_contract_in_all_recommendation_surfaces().
$this->assertSame( 'string', $ranking['properties']['designPrinciple']['type'] ?? null );
$this->assertSame( 'string', $ranking['properties']['risk']['type'] ?? null );

// Also assert the same properties on recommend-block settings/styles/block ranking schemas.
$this->assertSame( 'string', $block_ranking['properties']['designPrinciple']['type'] ?? null );
$this->assertSame( 'string', $block_ranking['properties']['risk']['type'] ?? null );
$this->assertSame( 'string', $styles_ranking['properties']['designPrinciple']['type'] ?? null );
$this->assertSame( 'string', $styles_ranking['properties']['risk']['type'] ?? null );
$this->assertSame( 'string', $blocks_ranking['properties']['designPrinciple']['type'] ?? null );
$this->assertSame( 'string', $blocks_ranking['properties']['risk']['type'] ?? null );

// In test_register_abilities_exposes_ranking_contract_in_pattern_recommendations().
$this->assertSame( 'string', $ranking['properties']['designPrinciple']['type'] ?? null );
$this->assertSame( 'string', $ranking['properties']['risk']['type'] ?? null );
```

The important contract is that ability output metadata documents the same camelCase `designPrinciple` and `risk` keys preserved by `RankingContract::normalize()` for block, style, template, template-part, navigation, and pattern recommendation outputs.

Add prompt-shape tests before changing the prompt text. Each strict-schema surface must prove its model-facing "exact shape" example includes the required nullable `ranking` property and a rule that the model should return `ranking: null` when it has no structured ranking object:

- `PromptRulesTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema()`
- `StylePromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema()`
- `TemplatePromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema()`
- `TemplatePartPromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema()`
- `NavigationAbilitiesTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema()`

Each test should call the relevant `build_system()` method and assert:

```php
$this->assertStringContainsString( '"ranking": null', $system );
$this->assertStringContainsString( 'return ranking as null', strtolower( $system ) );
$this->assertStringContainsString( 'use null for unknown ranking object values', strtolower( $system ) );
```

Use an equivalent assertion only if the implemented prompt text intentionally uses a clearer sentence. The tests must fail against the current prompts because the current JSON examples mention `confidence` but not `ranking`.

- [ ] **Step 2: Run schema tests and verify failure**

Run:

```bash
composer run test:php -- --filter 'ResponseSchemaTest|RankingContractTest::test_normalize_preserves_optional_ranking_context_fields|RankingContractTest::test_normalize_merges_model_and_deterministic_source_signals_without_dropping_plugin_signals|RegistrationTest::test_register_abilities_exposes_ranking_contract_in_all_recommendation_surfaces|RegistrationTest::test_register_abilities_exposes_ranking_contract_in_pattern_recommendations|PromptRulesTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|StylePromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|TemplatePromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|TemplatePartPromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|NavigationAbilitiesTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema'
```

Expected: FAIL because `ranking` is absent from strict schemas, prompt examples, and prompt rules, because `RankingContract::normalize()` currently drops the optional structured ranking context fields that the new schema accepts, because ability output schema does not yet expose those preserved context fields, and because model-provided `sourceSignals` currently replace plugin deterministic source signals instead of merging with them.

- [ ] **Step 3: Implement nullable ranking schema, prompt shape, and ranking context preservation**

In `inc/LLM/ResponseSchema.php`, add `ranking` to each strict item schema:

- In template suggestion properties.
- In template-part suggestion properties.
- In style suggestion properties.
- In navigation suggestion properties.
- In `block_display_metadata_schema()`.

Add this helper near `nullable_confidence()`:

```php
private static function nullable_ranking_schema(): array {
	$schema = self::strict_object(
		[
			'score'           => [
				'type'    => [ 'number', 'null' ],
				'minimum' => 0,
				'maximum' => 1,
			],
			'reason'          => self::nullable_string(),
			'sourceSignals'   => [
				'type'  => [ 'array', 'null' ],
				'items' => [ 'type' => 'string' ],
			],
			'designPrinciple' => self::nullable_string(),
			'risk'            => self::nullable_string(),
		]
	);

	$schema['type'] = [ 'object', 'null' ];

	return $schema;
}
```

For strict schemas, remember that `strict_object()` makes every listed object property required. The suggestion item itself must always include `ranking` because adding it to a strict suggestion item schema makes that top-level property required. The value may be either `null` or a ranking object. If it is a ranking object, it must include all five keys (`score`, `reason`, `sourceSignals`, `designPrinciple`, `risk`) and use `null` for any unknown value. Do not describe those object keys as optional unless the implementation deliberately stops using `strict_object()` for the ranking object and adds tests for partial objects.

In every affected `build_system()` prompt, update the JSON item examples so they include the required property:

```json
"ranking": null
```

Then add a rule near the existing `confidence` rule:

```text
- Every suggestion item MUST include ranking. Return ranking as null when you do not have a structured ranking object. When ranking is an object, include score, reason, sourceSignals, designPrinciple, and risk, and use null for unknown ranking object values; the plugin will blend score with deterministic validation signals.
```

Apply that prompt-shape change to block settings/style/block item examples, style suggestions, template suggestions, template-part suggestions, and navigation suggestions. Keep `confidence` for backward compatibility and as a model-score candidate, but do not leave the prompt implying that `confidence` is the only ranking field under strict JSON schema enforcement.

In `inc/Support/RankingContract.php`, preserve the optional ranking context fields after the `$contract` array is created:

```php
foreach ( [ 'designPrinciple', 'risk' ] as $context_key ) {
	$context_value = sanitize_text_field(
		(string) ( $input[ $context_key ] ?? $defaults[ $context_key ] ?? '' )
	);

	if ( '' !== $context_value ) {
		$contract[ $context_key ] = $context_value;
	}
}
```

Keep the camelCase keys unchanged so they match the strict LLM schema and ability output metadata.

Also update `RankingContract::normalize()` so input and default `sourceSignals` are merged, de-duplicated, sanitized, and ordered with plugin/default signals first. Parser code will pass deterministic plugin signals as defaults and model-provided signals as input; the model signals may add context, but they must not replace deterministic surface, safety, or actionability signals used by diagnostics and consumers.

In `inc/Abilities/Registration.php`, update `ranking_contract_schema()` so the public ability output contract exposes the preserved context fields:

```php
'designPrinciple' => [ 'type' => 'string' ],
'risk'            => [ 'type' => 'string' ],
```

Keep the schema open for existing surface-specific ranking metadata, but do not rely on `additionalProperties => true` as the only documentation for these two fields. They are now named ranking metadata fields emitted by `RankingContract::normalize()` and accepted by strict LLM ranking objects, so the ability schema should advertise them explicitly.

- [ ] **Step 4: Run schema and prompt-shape tests**

Run:

```bash
composer run test:php -- --filter 'ResponseSchemaTest|RankingContractTest::test_normalize_preserves_optional_ranking_context_fields|RankingContractTest::test_normalize_merges_model_and_deterministic_source_signals_without_dropping_plugin_signals|RegistrationTest::test_register_abilities_exposes_ranking_contract_in_all_recommendation_surfaces|RegistrationTest::test_register_abilities_exposes_ranking_contract_in_pattern_recommendations|PromptRulesTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|StylePromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|TemplatePromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|TemplatePartPromptTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema|NavigationAbilitiesTest::test_build_system_includes_required_nullable_ranking_shape_for_strict_schema'
```

Expected: PASS.

## Task 5: Blend Block Parser Scores

**Files:**
- Modify: `inc/LLM/Prompt.php`
- Modify: `tests/phpunit/PromptRulesTest.php`

- [ ] **Step 1: Replace first-score-wins tests with blended-score tests**

Update `test_parse_response_prefers_explicit_score_over_confidence_when_ranking_blocks()` to assert that deterministic quality can outrank a higher model score:

```php
public function test_parse_response_blends_model_score_with_deterministic_quality_when_ranking_blocks(): void {
	$result = Prompt::parse_response(
		wp_json_encode(
			[
				'block' => [
					[
						'label'       => 'High model advisory',
						'description' => 'Broad idea with no executable change.',
						'type'        => 'structural_recommendation',
						'ranking'     => [
							'score' => 0.9,
						],
					],
					[
						'label'            => 'Lower model executable update',
						'description'      => 'Concrete update with stronger deterministic quality.',
						'type'             => 'attribute_change',
						'attributeUpdates' => [
							'level' => 2,
						],
						'score'            => 0.55,
					],
				],
			]
		)
	);

	$this->assertIsArray( $result );
	$this->assertSame( 'Lower model executable update', $result['block'][0]['label'] );
	$this->assertGreaterThan( $result['block'][1]['ranking']['score'], $result['block'][0]['ranking']['score'] );
}
```

Update `test_parse_response_falls_back_when_nested_ranking_score_is_malformed()` so the expected `ranking.score` is the blended score, not raw confidence.

Also update the existing raw model-score assertions that will otherwise fail after blending:

```php
// test_parse_response_decodes_json_string_attribute_updates_from_compact_schema()
$this->assertSame( 0.858, $result['settings'][0]['ranking']['score'] );

// test_parse_response_normalizes_ranking_contract_when_confidence_present()
$this->assertSame( 0.674, $result['block'][0]['ranking']['score'] );

// test_parse_response_falls_back_when_nested_ranking_score_is_malformed()
$this->assertSame( 0.742, $result['block'][0]['ranking']['score'] );
```

Add a block parser regression proving model ranking metadata cannot replace plugin source signals:

```php
public function test_parse_response_merges_model_source_signals_with_plugin_ranking_signals(): void {
	$result = Prompt::parse_response(
		wp_json_encode(
			[
				'block' => [
					[
						'label'            => 'Concrete update with model signal',
						'description'      => 'Keep provider explanation without losing plugin diagnostics.',
						'type'             => 'attribute_change',
						'attributeUpdates' => [ 'level' => 2 ],
						'ranking'          => [
							'score'         => 0.61,
							'sourceSignals' => [ 'model_heading_hierarchy' ],
						],
					],
				],
			]
		)
	);

	$this->assertIsArray( $result );
	$this->assertSame(
		[ 'llm_response', 'block_surface', 'has_executable_updates', 'has_description', 'model_heading_hierarchy' ],
		$result['block'][0]['ranking']['sourceSignals']
	);
}
```

- [ ] **Step 2: Run block parser tests and verify failure**

Run:

```bash
composer run test:php -- --filter 'PromptRulesTest::test_parse_response_decodes_json_string_attribute_updates_from_compact_schema|PromptRulesTest::test_parse_response_normalizes_ranking_contract_when_confidence_present|PromptRulesTest::test_parse_response_blends_model_score_with_deterministic_quality_when_ranking_blocks|PromptRulesTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed|PromptRulesTest::test_parse_response_merges_model_source_signals_with_plugin_ranking_signals'
```

Expected: FAIL because the current parser still uses first numeric score/confidence.

- [ ] **Step 3: Update block parser scoring**

In `inc/LLM/Prompt.php`, replace the current `$computed_score` block with this pattern:

```php
$model_score = RankingContract::resolve_score_candidate(
	$ranking_input['score'] ?? null,
	$s['score'] ?? null,
	$ranking_input['confidence'] ?? null,
	$confidence
);

$deterministic_score = RankingContract::derive_score(
	0.45,
	[
		'has_executable_updates' => $has_executable_updates ? 0.25 : 0.0,
		'has_description'        => '' !== $normalized['description'] ? 0.15 : 0.0,
		'has_type'               => null !== $type && '' !== $type ? 0.05 : 0.0,
		'has_preview'            => null !== $normalized['preview'] ? 0.05 : 0.0,
	]
);

$computed_score = RankingContract::blend_score(
	[
		'model'         => $model_score,
		'deterministic' => $deterministic_score,
		'context'       => null,
	]
);
```

Before passing `$ranking_input` into `RankingContract::normalize()`, remove model scoring keys so the normalized ranking keeps the blended score:

```php
$ranking_metadata = $ranking_input;
unset( $ranking_metadata['score'], $ranking_metadata['confidence'] );
```

Do not remove `sourceSignals` from `$ranking_metadata`. `RankingContract::normalize()` must merge model-provided source signals after the plugin/default source signals, so provider metadata can add context without replacing deterministic surface, safety, or actionability signals.

Then call:

```php
$normalized['ranking'] = RankingContract::normalize(
	$ranking_metadata,
	[
		'score'         => $computed_score,
		'reason'        => (string) ( $s['description'] ?? '' ),
		'sourceSignals' => $source_signals,
		'safetyMode'    => 'validated',
		'freshnessMeta' => [
			'source' => 'llm',
			'group'  => $group,
		],
		'advisoryType'  => 'block' === $group ? (string) ( $s['type'] ?? '' ) : '',
	]
);
```

- [ ] **Step 4: Run focused block parser tests**

Run:

```bash
composer run test:php -- --filter 'PromptRulesTest::test_parse_response_decodes_json_string_attribute_updates_from_compact_schema|PromptRulesTest::test_parse_response_normalizes_ranking_contract_when_confidence_present|PromptRulesTest::test_parse_response_blends_model_score_with_deterministic_quality_when_ranking_blocks|PromptRulesTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed|PromptRulesTest::test_parse_response_merges_model_source_signals_with_plugin_ranking_signals|PromptRulesTest::test_parse_response_ranks_block_suggestions_by_computed_quality_signals'
```

Expected: PASS.

## Task 6: Blend Style, Template, Template-Part, And Navigation Parser Scores

**Files:**
- Modify: `inc/LLM/StylePrompt.php`
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Modify: `inc/LLM/NavigationPrompt.php`
- Modify: `tests/phpunit/StylePromptTest.php`
- Modify: `tests/phpunit/TemplatePromptTest.php`
- Modify: `tests/phpunit/TemplatePartPromptTest.php`
- Modify: `tests/phpunit/NavigationAbilitiesTest.php`

- [ ] **Step 1: Update parser tests from first-score-wins to blended-score behavior**

For each existing `test_parse_response_prefers_explicit_score_over_confidence_for_sorting()` test, rename it to:

```text
test_parse_response_blends_model_score_with_deterministic_quality_for_sorting
```

Use the same structure as Task 5:

- First suggestion: high model score, weak deterministic evidence.
- Second suggestion: lower model score, stronger deterministic evidence.
- Assertion: the stronger deterministic suggestion sorts first.

For each `test_parse_response_falls_back_when_nested_ranking_score_is_malformed()` test, update the score expectation from raw confidence to the blended score.

Use these expected blended scores for the current malformed-score fixtures:

```php
// StylePromptTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed()
$this->assertSame( 0.708, $result['suggestions'][0]['ranking']['score'] );

// TemplatePromptTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed()
$this->assertSame( 0.704, $result['suggestions'][0]['ranking']['score'] );

// TemplatePartPromptTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed()
$this->assertSame( 0.7, $result['suggestions'][0]['ranking']['score'] );

// NavigationAbilitiesTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed()
$this->assertSame( 0.764, $result['suggestions'][0]['ranking']['score'] );
```

For each parser family, add or update at least one assertion proving `ranking.sourceSignals` is merged with plugin deterministic signals instead of replacing them. The exact expected plugin signals differ by surface, but every assertion must prove the normalized ranking still contains the relevant surface signal (`style_surface`, `template_surface`, `template_part_surface`, or `navigation_surface`) plus the model-provided signal.

- [ ] **Step 2: Run focused tests and verify failure**

Run:

```bash
composer run test:php -- --filter 'StylePromptTest::test_parse_response_blends_model_score_with_deterministic_quality_for_sorting|StylePromptTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed|TemplatePromptTest::test_parse_response_blends_model_score_with_deterministic_quality_for_sorting|TemplatePromptTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed|TemplatePartPromptTest::test_parse_response_blends_model_score_with_deterministic_quality_for_sorting|TemplatePartPromptTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed|NavigationAbilitiesTest::test_parse_response_blends_model_score_with_deterministic_quality_for_sorting|NavigationAbilitiesTest::test_parse_response_falls_back_when_nested_ranking_score_is_malformed'
```

Expected: FAIL before parser changes.

- [ ] **Step 3: Apply the same blend pattern to all four parsers**

In each parser:

1. Keep the current deterministic `RankingContract::derive_score()` signal set.
2. Compute `$model_score` from `ranking.score`, top-level `score`, `ranking.confidence`, and top-level `confidence`.
3. Compute `$computed_score` with `RankingContract::blend_score()`.
4. Strip `score` and `confidence` from model-provided `$ranking_input` before `RankingContract::normalize()`.
5. Keep model-provided `sourceSignals` in `$ranking_metadata`, relying on `RankingContract::normalize()` to merge them after plugin/default source signals.

Use this pattern:

```php
$model_score = RankingContract::resolve_score_candidate(
	$ranking_input['score'] ?? null,
	$suggestion['score'] ?? null,
	$ranking_input['confidence'] ?? null,
	$suggestion['confidence'] ?? null
);

$deterministic_score = RankingContract::derive_score(
	0.45,
	$existing_signal_array
);

$computed_score = RankingContract::blend_score(
	[
		'model'         => $model_score,
		'deterministic' => $deterministic_score,
		'context'       => null,
	]
);

$ranking_metadata = $ranking_input;
unset( $ranking_metadata['score'], $ranking_metadata['confidence'] );
```

Reuse the existing parser-specific signals as the base deterministic component. Add only the narrow metric-backed deterministic signals needed by the ranked parser fixtures when the live parser already has enough sanitized data or context to compute them, such as no-op avoidance from current state, preset-backed value preference from validated operations, or invalid/downgraded operation penalties from existing validator output. Do not add design-semantics collection, guideline fingerprints, pattern component scoring, or new browser/UI state in this phase.

- [ ] **Step 4: Run the focused parser tests**

Run:

```bash
composer run test:php -- --filter 'StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest'
```

Expected: PASS.

## Task 7: Re-Run Phase 0 Metrics After Phase 1

**Files:**
- Modify: `tests/phpunit/RecommendationEvaluationTest.php` only if the parser-backed metric assertion added in Task 2 needs to be adjusted for legitimate parser-output changes.

- [ ] **Step 1: Run Phase 0 baseline again**

Run:

```bash
composer run test:php -- --filter RecommendationEvaluationTest
```

Expected: PASS with unchanged static baseline fixture metrics, stable parser all-suggestion metric assertions, and updated ranked parser top-suggestion assertions that show the expected Phase 1 movement.

- [ ] **Step 2: Confirm parser-backed metric coverage still uses live parser output**

The parser-backed fixtures from Task 2 are mandatory and should already call the relevant live parser before feeding parsed suggestions into `evaluate()`. Do not replace them with hard-coded normalized suggestions while updating Phase 1 ranking behavior. Keep provider calls mocked out.

The required assertion should still resemble the Task 2 parser-backed test shape:

```php
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
			$this->assertIsArray( $expected_top_ranked );
		}

		if ( is_array( $expected_top_ranked ) ) {
			$this->assertSame(
				self::normalize_expected_metrics( $expected_top_ranked ),
				self::round_metric_values( self::evaluate( [ self::top_ranked_fixture( $materialized ) ] ) )
			);
		}
	}
}
```

Then compare the Phase 0 and Phase 1 ranked-parser baselines in the implementation notes. The static fixture baseline should not be retuned. For ranked parser probes, record the before/after top-ranked metric movement and explain any unchanged metric with the exact parser/context limitation that prevents movement in this phase.

## Task 8: Full Verification

**Files:**
- No new files unless verification reveals doc drift.

- [ ] **Step 1: Run focused PHP tests**

Run:

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|ResponseSchemaTest|RankingContractTest|PromptRulesTest|StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest'
```

Expected: PASS.

- [ ] **Step 2: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 3: Run whitespace check**

Run:

```bash
git diff --check
```

Expected: no output and exit 0.

- [ ] **Step 4: Run the required cross-surface non-browser aggregate**

This plan changes shared recommendation contracts and parser behavior across more than one surface, so the cross-surface gate is required before the work is considered complete:

```bash
node scripts/verify.js --skip-e2e
```

Expected: `VERIFY_RESULT` reports `status: "pass"` and `output/verify/summary.json` records passing `build`, `lint-js`, `lint-plugin`, `unit`, `lint-php`, and `test-php` steps. If the run is `incomplete` because `wp`, `bash`, or `WP_PLUGIN_CHECK_PATH` is unavailable, record that as an environment blocker; do not treat it as a pass. If it fails on unrelated dirty settings work, record the blocker without widening this plan.

- [ ] **Step 5: Record browser evidence or an explicit waiver**

This Phase 0/1 slice is intended to be PHP/schema/parser-only and should not edit `src/`, `build/`, or wp-admin/editor UI code. If that remains true, record an explicit browser waiver in the implementation notes: "No Playwright run: no browser bundle, UI, or editor interaction changed; covered by parser/schema/PHP gates." If implementation expands into UI or source JS, run the matching harness instead of waiving:

```bash
npm run test:e2e:playground
npm run test:e2e:wp70
```

Expected: either the waiver is recorded with the no-browser-change rationale, or the matching Playwright harnesses pass or have a documented known-red/environment blocker.

## Self-Review Checklist

- [ ] Phase 0 baseline exists before Phase 1 ranking changes.
- [ ] Phase 0 fixture counts match the recorded metric math, including 4 accepted operations, 2 rejected operations, and suggestion-level noise accounting.
- [ ] `tests/phpunit/fixtures/` is created before writing Phase 0 fixture files.
- [ ] Phase 0 parser-backed fixtures call live parsers and feed parsed suggestions through the same `evaluate()` function before Phase 1 parser changes start.
- [ ] Ranked parser probes exist for no-op, preset adherence, and invalid/downgraded operation behavior, with `expectedTopRankedMetrics` recorded before Phase 1 ranking changes.
- [ ] Task 7 records the Phase 0 versus Phase 1 top-ranked parser metric movement instead of treating unchanged all-suggestion metrics as sufficient ranking evidence.
- [ ] No provider calls are introduced.
- [ ] `RankingContract::normalize()` does not accidentally overwrite blended scores with model-provided `ranking.score` or `confidence`.
- [ ] `RankingContract::normalize()` merges model `ranking.sourceSignals` after deterministic plugin/default signals, so provider metadata cannot replace surface/actionability diagnostics.
- [ ] `RankingContract::normalize()` preserves `designPrinciple` and `risk` when strict schemas accept those ranking fields.
- [ ] Ability output schemas explicitly expose `ranking.designPrinciple` and `ranking.risk`; they are not left as undocumented open-object properties.
- [ ] Strict LLM schemas remain strict: no broad `additionalProperties`, and ranking object schemas require `score`, `reason`, `sourceSignals`, `designPrinciple`, and `risk` with nullable values.
- [ ] Prompt JSON examples and rules for block/style/template/template-part/navigation include required nullable `ranking`, and any ranking object guidance tells providers to include all strict ranking keys with `null` for unknown values.
- [ ] Existing deterministic `derive_score()` signals are reused as the base for Phase 1, with only narrow metric-backed validation/no-op/preset signals added where the live parser already has enough data.
- [ ] Parser tests cover all five surfaces.
- [ ] Pattern recommendations remain browse/rank-only and untouched by this plan.
- [ ] `node scripts/verify.js --skip-e2e` passes or records an explicit environment/unrelated-work blocker in `output/verify/summary.json`.
- [ ] Browser evidence is either provided for touched UI surfaces or explicitly waived because this phase remained PHP/schema/parser-only.
