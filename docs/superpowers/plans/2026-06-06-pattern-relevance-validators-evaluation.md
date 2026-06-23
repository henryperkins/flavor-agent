# Pattern Relevance Validators Evaluation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve recommendation quality by expanding offline metrics first, then shipping pattern component ranking and deterministic design validators behind measurable fixture gates.

**Architecture:** Add measurement before ranking changes so every later change has a metric contract. Pattern ranking gets two focused domain helpers: one extractor for structured pattern design metadata and one scorer for semantic, structure, design, area, and override component scores. Cross-surface design validators feed `validationReasons`, `ranking.sourceSignals`, and contextual ranking evidence without widening apply/undo semantics or changing the pattern surface from browse/rank-only.

**Tech Stack:** PHP 8.0+, WordPress 7.0+ block editor contracts, PHPUnit fixtures, existing `@wordpress/scripts` JS tests, Qdrant and Cloudflare AI Search pattern retrieval payloads, `RankingContract`, `RecommendationContextScorer`, `ValidationReason`, and existing per-surface prompt parsers.

---

## Current Evidence

- `docs/reference/current-open-work.md` lists this exact cluster as the suggested first planning order: pattern relevance, missing design validators, and expanded evaluation harness.
- `improving-levers.md` marks Phases 0-3 shipped, Phase 4 request-diagnostic guideline attribution shipped, and Phases 5-12 unshipped. This plan implements the Phase 6 and Phase 7 parts that are intentionally sequenced before the future learning loop.
- `tests/phpunit/RecommendationEvaluationTest.php` currently measures `invalidOperationRate`, `presetAdherenceRate`, `noOpRate`, and `noiseRate` only.
- `inc/Patterns/PatternIndex.php` currently infers flat `traits` but has no structured `designMetadata` payload.
- `inc/Abilities/PatternAbilities.php` currently adds `rankingHint` and contextual ranking, but does not expose `ranking.rankingHint.componentScores`.
- WordPress 7.0 developer guidance has current design-tool coverage for alignment, typography, color, dimensions, border, layout, gradients, duotone, shadows, background images, and registered block supports. Validators should read support/theme-token context rather than hard-coding unsupported block behavior.

## Scope Boundaries

- Do not implement adapted pattern preview in this plan.
- Do not implement docs content/runtime fingerprint splitting in this plan.
- Do not add pattern apply/undo or a review lane.
- Do not add automatic learned ranking adjustments.
- Do not persist routine successful validation diagnostics; only expose compact ranking evidence and validator signals.

## File Map

- Modify: `tests/phpunit/RecommendationEvaluationTest.php` - add expanded metrics and fixture materialization support.
- Modify: `tests/phpunit/fixtures/recommendation-evaluation-baseline.json` - record new metric keys with baseline values.
- Create: `tests/phpunit/fixtures/recommendation-evaluation-expanded-fixtures.php` - broad fixtures for contrast, top-three relevance, stale false positives, and prompt token deltas.
- Modify: `tests/phpunit/PatternIndexTest.php` - prove metadata extraction and payload persistence.
- Modify: `tests/phpunit/PatternAbilitiesTest.php` - prove component scores round-trip and ranking changes preserve visible/readable filtering.
- Modify: `tests/phpunit/RecommendationContextScorerTest.php` - prove new validator evidence and penalties affect context scores.
- Modify: `tests/phpunit/RankingContractTest.php` - prove `rankingHint.componentScores` survives normalization.
- Modify: `tests/phpunit/StylePromptTest.php`, `tests/phpunit/PromptValidationReasonsTest.php`, `tests/phpunit/TemplatePromptTest.php`, and `tests/phpunit/TemplatePartPromptTest.php` - prove validator signals remain bounded per surface.
- Create: `inc/Patterns/PatternDesignMetadata.php` - extract structured design metadata from pattern payloads/content.
- Create: `inc/Patterns/PatternComponentScorer.php` - compute component scores for pattern candidates.
- Modify: `inc/Patterns/PatternIndex.php` - include metadata in embedding text, Qdrant payloads, Cloudflare index documents, and pattern fingerprints by bumping the recipe version.
- Modify: `inc/Patterns/Retrieval/CloudflareAISearchPatternRetrievalBackend.php` - rehydrate `designMetadata` for current registered patterns and keep synced references empty.
- Modify: `inc/Abilities/PatternAbilities.php` - compute component scores, pass them to the LLM candidate payload, and expose them through `ranking.rankingHint.componentScores`.
- Create: `inc/Support/RecommendationDesignValidator.php` - derive cross-surface deterministic design validation signals.
- Modify: `inc/Support/RecommendationContextScorer.php` - consume new quality evidence and penalties.
- Modify: `inc/Support/RankingContract.php` - allow normalized component scores and new evidence/penalty keys.
- Modify: `inc/LLM/Prompt.php`, `inc/LLM/StylePrompt.php`, `inc/LLM/TemplatePrompt.php`, and `inc/LLM/TemplatePartPrompt.php` - attach validator signals to parsed suggestions where surface context is strong enough.
- Modify: `docs/reference/current-open-work.md`, `improving-levers.md`, and `docs/reference/pattern-recommendation-debugging.md` after implementation - update status and verification evidence.

---

## Task 1: Expand The Offline Evaluation Harness Before Ranking Changes

**Files:**
- Modify: `tests/phpunit/RecommendationEvaluationTest.php`
- Modify: `tests/phpunit/fixtures/recommendation-evaluation-baseline.json`
- Create: `tests/phpunit/fixtures/recommendation-evaluation-expanded-fixtures.php`

- [ ] **Step 1: Add the failing expanded-metrics test**

Add this test after `test_contextual_ranking_parser_fixtures_choose_expected_top_suggestions()`:

```php
public function test_expanded_quality_metrics_match_recorded_fixture_output(): void {
	$fixtures = require __DIR__ . '/fixtures/recommendation-evaluation-expanded-fixtures.php';

	$this->assertSame(
		[
			'fixtures'                 => 4,
			'suggestions'              => 8,
			'invalidOperationRate'     => 0.125,
			'presetAdherenceRate'      => 0.75,
			'noOpRate'                 => 0.125,
			'noiseRate'                => 0.0,
			'contrastPreservationRate' => 0.75,
			'topThreeRelevanceRate'    => 0.75,
			'staleFalsePositiveRate'   => 0.25,
			'promptTokenDeltaMax'      => 72,
		],
		self::round_metric_values( self::evaluate( $fixtures ) )
	);
}
```

- [ ] **Step 2: Add fixture data that exercises each new metric**

Create `tests/phpunit/fixtures/recommendation-evaluation-expanded-fixtures.php`:

```php
<?php

declare(strict_types=1);

return [
	'pattern_top_three_relevance' => [
		'surface'              => 'pattern',
		'expectedRelevantKeys' => [ 'theme/hero-contrast', 'theme/hero-centered' ],
		'suggestions'          => [
			[
				'name'    => 'theme/hero-contrast',
				'ranking' => [ 'score' => 0.91 ],
			],
			[
				'name'    => 'theme/pricing-columns',
				'ranking' => [ 'score' => 0.72 ],
			],
			[
				'name'    => 'theme/testimonial-strip',
				'ranking' => [ 'score' => 0.65 ],
			],
		],
	],
	'style_contrast_and_preset_quality' => [
		'surface'     => 'style',
		'suggestions' => [
			[
				'label'          => 'Use readable accent contrast',
				'operations'     => [
					[
						'type'       => 'set_styles',
						'path'       => [ 'color', 'text' ],
						'value'      => 'var:preset|color|contrast',
						'valueType'  => 'preset',
						'presetType' => 'color',
						'presetSlug' => 'contrast',
					],
				],
				'qualitySignals' => [
					'contrastPreserved' => true,
				],
			],
			[
				'label'             => 'Use faint raw text',
				'operations'        => [
					[
						'type'  => 'set_styles',
						'path'  => [ 'color', 'text' ],
						'value' => '#eeeeee',
					],
				],
				'validationReasons' => [
					[ 'code' => 'failed_contrast', 'severity' => 'error' ],
				],
				'qualitySignals'    => [
					'contrastPreserved' => false,
				],
			],
		],
	],
	'stale_false_positive_probe' => [
		'surface'                     => 'block',
		'shouldRemainApplicable'      => true,
		'wasMarkedStale'              => true,
		'promptTokenBaselineEstimate' => 120,
		'promptTokenEstimate'         => 192,
		'suggestions'                 => [
			[
				'label'            => 'Keep current button style',
				'attributeUpdates' => [ 'className' => 'is-style-fill' ],
			],
		],
		'currentState'                => [
			'attributes' => [ 'className' => 'is-style-fill' ],
		],
	],
	'invalid_operation_probe' => [
		'surface'     => 'template',
		'suggestions' => [
			[
				'label'              => 'Insert valid footer CTA',
				'operations'         => [
					[
						'type' => 'insert_pattern',
						'name' => 'theme/footer-cta',
					],
				],
				'rejectedOperations' => [
					[ 'reason' => 'unsupported path' ],
				],
			],
		],
	],
];
```

- [ ] **Step 3: Run the focused test and confirm it fails**

Run:

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest::test_expanded_quality_metrics_match_recorded_fixture_output'
```

Expected: FAIL because `contrastPreservationRate`, `topThreeRelevanceRate`, `staleFalsePositiveRate`, and `promptTokenDeltaMax` are not produced yet.

- [ ] **Step 4: Add metric calculations to `evaluate()`**

Inside `evaluate()`, add counters:

```php
$contrast_candidates          = 0;
$contrast_preserved           = 0;
$top_three_relevance_fixtures = 0;
$top_three_relevant_hits      = 0;
$stale_applicable_count       = 0;
$stale_false_positive_count   = 0;
$prompt_token_delta_max       = 0;
```

Inside the fixture loop, before iterating suggestions, add:

```php
$expected_relevant_keys = is_array( $fixture['expectedRelevantKeys'] ?? null )
	? array_values( array_map( 'strval', $fixture['expectedRelevantKeys'] ) )
	: [];
if ( [] !== $expected_relevant_keys ) {
	++$top_three_relevance_fixtures;
	$top_three_keys = array_map(
		static fn( array $suggestion ): string => sanitize_text_field(
			(string) ( $suggestion['name'] ?? $suggestion['suggestionKey'] ?? $suggestion['label'] ?? '' )
		),
		array_slice( array_filter( $suggestions, 'is_array' ), 0, 3 )
	);
	if ( [] !== array_intersect( $expected_relevant_keys, $top_three_keys ) ) {
		++$top_three_relevant_hits;
	}
}

if ( ! empty( $fixture['shouldRemainApplicable'] ) ) {
	++$stale_applicable_count;
	if ( ! empty( $fixture['wasMarkedStale'] ) ) {
		++$stale_false_positive_count;
	}
}

$baseline_tokens = isset( $fixture['promptTokenBaselineEstimate'] ) && is_numeric( $fixture['promptTokenBaselineEstimate'] )
	? (int) $fixture['promptTokenBaselineEstimate']
	: null;
$actual_tokens = isset( $fixture['promptTokenEstimate'] ) && is_numeric( $fixture['promptTokenEstimate'] )
	? (int) $fixture['promptTokenEstimate']
	: null;
if ( null !== $baseline_tokens && null !== $actual_tokens ) {
	$prompt_token_delta_max = max( $prompt_token_delta_max, max( 0, $actual_tokens - $baseline_tokens ) );
}
```

Inside the suggestion loop, add:

```php
$quality_signals = is_array( $suggestion['qualitySignals'] ?? null ) ? $suggestion['qualitySignals'] : [];
if ( array_key_exists( 'contrastPreserved', $quality_signals ) ) {
	++$contrast_candidates;
	if ( ! empty( $quality_signals['contrastPreserved'] ) ) {
		++$contrast_preserved;
	}
}
```

Add these keys to the return array:

```php
'contrastPreservationRate' => self::rate( $contrast_preserved, $contrast_candidates ),
'topThreeRelevanceRate'    => self::rate( $top_three_relevant_hits, $top_three_relevance_fixtures ),
'staleFalsePositiveRate'   => self::rate( $stale_false_positive_count, $stale_applicable_count ),
'promptTokenDeltaMax'      => $prompt_token_delta_max,
```

Update `normalize_expected_metrics()` so it rounds the new rate keys:

```php
foreach ( [ 'invalidOperationRate', 'presetAdherenceRate', 'noOpRate', 'noiseRate', 'contrastPreservationRate', 'topThreeRelevanceRate', 'staleFalsePositiveRate' ] as $key ) {
```

- [ ] **Step 5: Update the baseline JSON with zero-valued new metric keys**

Add these keys to `tests/phpunit/fixtures/recommendation-evaluation-baseline.json`:

```json
{
  "contrastPreservationRate": 0,
  "topThreeRelevanceRate": 0,
  "staleFalsePositiveRate": 0,
  "promptTokenDeltaMax": 0
}
```

Preserve the existing metric values in that file.

- [ ] **Step 6: Run focused evaluation tests**

Run:

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest'
```

Expected: PASS.

---

## Task 2: Add Structured Pattern Design Metadata

**Files:**
- Create: `inc/Patterns/PatternDesignMetadata.php`
- Modify: `inc/Patterns/PatternIndex.php`
- Modify: `inc/Patterns/Retrieval/CloudflareAISearchPatternRetrievalBackend.php`
- Modify: `tests/phpunit/PatternIndexTest.php`

- [ ] **Step 1: Write failing metadata extraction tests**

Add tests to `tests/phpunit/PatternIndexTest.php`:

```php
public function test_pattern_design_metadata_identifies_hero_cover_shape_and_mood(): void {
	$metadata = \FlavorAgent\Patterns\PatternDesignMetadata::extract(
		[
			'name'       => 'theme/hero',
			'categories' => [ 'featured' ],
			'content'    => '<!-- wp:cover {"dimRatio":70,"backgroundColor":"contrast"} --><!-- wp:heading --><h2>Hero</h2><!-- /wp:heading --><!-- wp:buttons --><!-- /wp:buttons --><!-- /wp:cover -->',
		]
	);

	$this->assertSame( 'hero', $metadata['sectionRole'] );
	$this->assertSame( 'cover', $metadata['layoutShape'] );
	$this->assertSame( 'dark', $metadata['colorMood'] );
	$this->assertSame( 'marketing', $metadata['typographyRole'] );
	$this->assertSame( 'content', $metadata['templateAreaAffinity'] );
	$this->assertContains( 'contrast', $metadata['styleTokenUsage'] );
}

public function test_embedding_text_includes_pattern_design_metadata(): void {
	$text = PatternIndex::build_embedding_text(
		[
			'name'       => 'theme/footer-links',
			'title'      => 'Footer Links',
			'categories' => [ 'footer' ],
			'content'    => '<!-- wp:group --><!-- wp:navigation /--><!-- wp:social-links /--><!-- /wp:group -->',
		]
	);

	$this->assertStringContainsString( 'Design metadata:', $text );
	$this->assertStringContainsString( 'templateAreaAffinity=footer', $text );
	$this->assertStringContainsString( 'interactionRole=navigation', $text );
}
```

- [ ] **Step 2: Run tests and confirm failure**

Run:

```bash
composer run test:php -- --filter 'PatternIndexTest::test_pattern_design_metadata_identifies_hero_cover_shape_and_mood|PatternIndexTest::test_embedding_text_includes_pattern_design_metadata'
```

Expected: FAIL because `PatternDesignMetadata` does not exist and `build_embedding_text()` does not include metadata.

- [ ] **Step 3: Create `PatternDesignMetadata`**

Create `inc/Patterns/PatternDesignMetadata.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns;

use FlavorAgent\Support\StringArray;

final class PatternDesignMetadata {

	/**
	 * @param array<string, mixed> $pattern
	 * @return array<string, mixed>
	 */
	public static function extract( array $pattern ): array {
		$content    = is_string( $pattern['content'] ?? null ) ? $pattern['content'] : '';
		$categories = StringArray::sanitize( $pattern['categories'] ?? [] );
		$traits     = PatternIndex::infer_layout_traits( $pattern );

		$metadata = [
			'sectionRole'          => self::section_role( $content, $categories, $traits ),
			'layoutShape'          => self::layout_shape( $content ),
			'visualDensity'        => self::visual_density( $content ),
			'colorMood'            => self::color_mood( $content ),
			'typographyRole'       => self::typography_role( $content, $categories ),
			'interactionRole'      => self::interaction_role( $content ),
			'templateAreaAffinity' => self::template_area_affinity( $categories, $traits ),
			'styleTokenUsage'      => self::style_token_usage( $content ),
			'blockComplexity'      => self::block_complexity( $content ),
			'contentSpecificity'   => self::content_specificity( $content ),
		];

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public static function summarize( array $metadata ): string {
		$parts = [];
		foreach ( $metadata as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( [] !== $value ) {
					$parts[] = $key . '=' . implode( '|', array_map( 'strval', $value ) );
				}
				continue;
			}

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$parts[] = $key . '=' . (string) $value;
			}
		}

		return implode( '; ', $parts );
	}

	private static function section_role( string $content, array $categories, array $traits ): string {
		$category_set = array_fill_keys( $categories, true );
		$trait_set    = array_fill_keys( $traits, true );

		if ( isset( $category_set['header'] ) || str_contains( $content, 'wp:navigation' ) || str_contains( $content, 'wp:site-title' ) ) {
			return 'header';
		}
		if ( isset( $category_set['footer'] ) ) {
			return 'footer';
		}
		if ( isset( $trait_set['pricing'] ) ) {
			return 'pricing';
		}
		if ( isset( $trait_set['testimonial'] ) ) {
			return 'testimonial';
		}
		if ( isset( $trait_set['query-loop'] ) ) {
			return 'query-listing';
		}
		if ( isset( $trait_set['hero-banner'] ) ) {
			return 'hero';
		}
		if ( str_contains( $content, 'wp:buttons' ) ) {
			return 'cta';
		}

		return 'unknown';
	}

	private static function layout_shape( string $content ): string {
		if ( str_contains( $content, 'wp:cover' ) ) {
			return 'cover';
		}
		if ( str_contains( $content, 'wp:media-text' ) ) {
			return 'media-left';
		}
		if ( str_contains( $content, 'wp:columns' ) ) {
			return substr_count( $content, 'wp:column' ) > 2 ? 'grid' : 'two-column';
		}
		if ( str_contains( $content, 'wp:group' ) ) {
			return 'single-column';
		}

		return 'unknown';
	}

	private static function visual_density( string $content ): string {
		$count = substr_count( $content, '<!-- wp:' );
		if ( $count <= 3 ) {
			return 'sparse';
		}
		if ( $count <= 10 ) {
			return 'balanced';
		}

		return 'dense';
	}

	private static function color_mood( string $content ): string {
		$lower = strtolower( $content );
		if ( str_contains( $lower, 'backgroundcolor":"contrast' ) || str_contains( $lower, 'dimratio":70' ) || str_contains( $lower, 'dimratio":80' ) || str_contains( $lower, 'dimratio":90' ) ) {
			return 'dark';
		}
		if ( str_contains( $lower, 'backgroundcolor":"base' ) || str_contains( $lower, 'backgroundcolor":"white' ) ) {
			return 'light';
		}
		if ( str_contains( $lower, 'backgroundcolor":"accent' ) ) {
			return 'accent';
		}
		if ( str_contains( $lower, 'wp:image' ) || str_contains( $lower, 'wp:cover' ) || str_contains( $lower, 'wp:gallery' ) ) {
			return 'image-heavy';
		}

		return 'unknown';
	}

	private static function typography_role( string $content, array $categories ): string {
		if ( str_contains( $content, 'wp:post-title' ) || str_contains( $content, 'wp:post-date' ) || in_array( 'query', $categories, true ) ) {
			return 'metadata-heavy';
		}
		if ( str_contains( $content, 'wp:heading' ) && str_contains( $content, 'wp:buttons' ) ) {
			return 'marketing';
		}
		if ( str_contains( $content, 'wp:navigation' ) ) {
			return 'navigation';
		}
		if ( str_contains( $content, 'wp:paragraph' ) ) {
			return 'editorial';
		}

		return 'unknown';
	}

	private static function interaction_role( string $content ): string {
		if ( str_contains( $content, 'wp:navigation' ) ) {
			return 'navigation';
		}
		if ( str_contains( $content, 'wp:search' ) || str_contains( $content, 'wp:form' ) ) {
			return 'form';
		}
		if ( str_contains( $content, 'wp:social-links' ) || str_contains( $content, 'wp:buttons' ) ) {
			return 'link-collection';
		}

		return 'static-content';
	}

	private static function template_area_affinity( array $categories, array $traits ): string {
		if ( in_array( 'header', $categories, true ) ) {
			return 'header';
		}
		if ( in_array( 'footer', $categories, true ) ) {
			return 'footer';
		}
		if ( in_array( 'site-chrome', $traits, true ) ) {
			return 'root';
		}

		return 'content';
	}

	/**
	 * @return string[]
	 */
	private static function style_token_usage( string $content ): array {
		preg_match_all( '/var:preset\|(?:color|spacing|font-size|font-family|shadow|duotone)\|([a-z0-9_-]+)/i', $content, $matches );
		preg_match_all( '/wp--preset--(?:color|spacing|font-size|font-family|shadow|duotone)--([a-z0-9_-]+)/i', $content, $css_matches );

		return array_values( array_unique( array_merge( $matches[1] ?? [], $css_matches[1] ?? [] ) ) );
	}

	private static function block_complexity( string $content ): string {
		$count = substr_count( $content, '<!-- wp:' );
		if ( $count <= 3 ) {
			return 'low';
		}
		if ( $count <= 10 ) {
			return 'medium';
		}

		return 'high';
	}

	private static function content_specificity( string $content ): string {
		$text = wp_strip_all_tags( strip_shortcodes( $content ) );
		return preg_match( '/\b(pricing|testimonial|portfolio|contact|team|event|menu|product)\b/i', $text ) ? 'topic-specific' : 'generic';
	}
}
```

- [ ] **Step 4: Wire metadata into `PatternIndex`**

In `PatternIndex.php`, add:

```php
use FlavorAgent\Patterns\PatternDesignMetadata;
```

Increment:

```php
public const EMBEDDING_RECIPE_VERSION = 4;
```

In `build_embedding_text()`, after layout traits, add:

```php
$design_metadata = PatternDesignMetadata::extract( $pattern );
$metadata_summary = PatternDesignMetadata::summarize( $design_metadata );
if ( '' !== $metadata_summary ) {
	$parts[] = 'Design metadata: ' . $metadata_summary;
}
```

In `embed_and_build_points()`, add to payload:

```php
'designMetadata'      => PatternDesignMetadata::extract( $p ),
```

- [ ] **Step 5: Rehydrate metadata in Cloudflare retrieval**

In `CloudflareAISearchPatternRetrievalBackend::payload_for_hit()`, after `ServerCollector::for_pattern( $name )` returns a pattern:

```php
$current_pattern['designMetadata'] = PatternIndex::design_metadata_for_pattern( $current_pattern );
```

Add this public helper to `PatternIndex`:

```php
/**
 * @param array<string, mixed> $pattern
 * @return array<string, mixed>
 */
public static function design_metadata_for_pattern( array $pattern ): array {
	return PatternDesignMetadata::extract( $pattern );
}
```

- [ ] **Step 6: Run metadata tests**

Run:

```bash
composer run test:php -- --filter 'PatternIndexTest'
```

Expected: PASS.

---

## Task 3: Add Pattern Component Scores And Round-Trip Ranking Hints

**Files:**
- Create: `inc/Patterns/PatternComponentScorer.php`
- Modify: `inc/Abilities/PatternAbilities.php`
- Modify: `tests/phpunit/PatternAbilitiesTest.php`
- Modify: `tests/phpunit/RankingContractTest.php`

- [ ] **Step 1: Write failing component-score normalization test**

Add to `tests/phpunit/RankingContractTest.php`:

```php
public function test_ranking_hint_component_scores_round_trip(): void {
	$ranking = RankingContract::normalize(
		[],
		[
			'score'       => 0.82,
			'rankingHint' => [
				'componentScores' => [
					'semantic'  => 0.80,
					'structure' => 0.70,
					'design'    => 0.90,
					'area'      => 0.60,
					'override'  => 0.40,
					'blended'   => 0.74,
				],
			],
		]
	);

	$this->assertSame(
		[
			'semantic'  => 0.80,
			'structure' => 0.70,
			'design'    => 0.90,
			'area'      => 0.60,
			'override'  => 0.40,
			'blended'   => 0.74,
		],
		$ranking['rankingHint']['componentScores']
	);
}
```

- [ ] **Step 2: Write failing pattern ability test**

Add a test to `tests/phpunit/PatternAbilitiesTest.php` that stubs two candidates with equal semantic scores but different metadata/context fit. The expected assertion:

```php
$ranking = $response['recommendations'][0]['ranking'] ?? [];
$this->assertSame( 'contextual-ranking-v1', $ranking['rankingVersion'] ?? null );
$this->assertIsArray( $ranking['rankingHint']['componentScores'] ?? null );
$this->assertGreaterThan( 0.0, $ranking['rankingHint']['componentScores']['design'] ?? 0.0 );
$this->assertContains( 'component_ranking_v1', $ranking['sourceSignals'] ?? [] );
```

Use the existing `PatternAbilitiesTest` recommendation stubs and mirror the local fixture style instead of adding live provider calls.

- [ ] **Step 3: Run tests and confirm failure**

Run:

```bash
composer run test:php -- --filter 'RankingContractTest::test_ranking_hint_component_scores_round_trip|PatternAbilitiesTest'
```

Expected: FAIL because component scores are not computed or exposed.

- [ ] **Step 4: Create `PatternComponentScorer`**

Create `inc/Patterns/PatternComponentScorer.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Patterns;

use FlavorAgent\Support\StringArray;

final class PatternComponentScorer {

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $context
	 * @return array{semantic: float, structure: float, design: float, area: float, override: float, blended: float}
	 */
	public static function score( float $semantic_score, array $payload, array $context ): array {
		$semantic  = self::clamp( $semantic_score );
		$structure = self::structure_score( $payload, $context );
		$design    = self::design_score( $payload, $context );
		$area      = self::area_score( $payload, $context );
		$override  = self::override_score( $payload, $context );

		$blended = self::clamp(
			( 0.45 * $semantic )
			+ ( 0.25 * $structure )
			+ ( 0.15 * $design )
			+ ( 0.10 * $area )
			+ ( 0.05 * $override )
		);

		return [
			'semantic'  => round( $semantic, 4 ),
			'structure' => round( $structure, 4 ),
			'design'    => round( $design, 4 ),
			'area'      => round( $area, 4 ),
			'override'  => round( $override, 4 ),
			'blended'   => round( $blended, 4 ),
		];
	}

	private static function structure_score( array $payload, array $context ): float {
		$traits   = array_fill_keys( StringArray::sanitize( $payload['traits'] ?? [] ), true );
		$root     = sanitize_key( (string) ( $context['rootBlock'] ?? '' ) );
		$siblings = StringArray::sanitize( $context['nearbySiblings'] ?? [] );

		$score = 0.55;
		if ( isset( $traits['simple'] ) && in_array( $root, [ 'core/group', 'core/column' ], true ) ) {
			$score += 0.18;
		}
		if ( isset( $traits['multi-column'] ) && in_array( 'core/columns', $siblings, true ) ) {
			$score += 0.12;
		}
		if ( isset( $traits['navigation'] ) && in_array( $root, [ 'core/navigation', 'core/group' ], true ) ) {
			$score += 0.10;
		}
		if ( isset( $traits['complex'] ) && in_array( $root, [ 'core/column', 'core/buttons' ], true ) ) {
			$score -= 0.20;
		}

		return self::clamp( $score );
	}

	private static function design_score( array $payload, array $context ): float {
		$metadata = is_array( $payload['designMetadata'] ?? null ) ? $payload['designMetadata'] : [];
		$context_role = sanitize_key( (string) ( $context['sectionRole'] ?? $context['templatePartArea'] ?? '' ) );
		$section_role = sanitize_key( (string) ( $metadata['sectionRole'] ?? '' ) );
		$density      = sanitize_key( (string) ( $metadata['visualDensity'] ?? '' ) );

		$score = 0.55;
		if ( '' !== $context_role && $context_role === $section_role ) {
			$score += 0.22;
		}
		if ( 'balanced' === $density || 'sparse' === $density ) {
			$score += 0.08;
		}
		if ( 'dense' === $density && in_array( $context_role, [ 'footer', 'sidebar', 'header' ], true ) ) {
			$score -= 0.18;
		}

		return self::clamp( $score );
	}

	private static function area_score( array $payload, array $context ): float {
		$metadata = is_array( $payload['designMetadata'] ?? null ) ? $payload['designMetadata'] : [];
		$area     = sanitize_key( (string) ( $context['templatePartArea'] ?? '' ) );
		$affinity = sanitize_key( (string) ( $metadata['templateAreaAffinity'] ?? '' ) );

		if ( '' === $area || 'unknown' === $affinity ) {
			return 0.55;
		}

		return $area === $affinity ? 0.90 : 0.40;
	}

	private static function override_score( array $payload, array $context ): float {
		$overrides       = is_array( $payload['patternOverrides'] ?? null ) ? $payload['patternOverrides'] : [];
		$custom_context  = ! empty( $context['isCustomBlockContext'] );
		$has_overrides   = ! empty( $overrides['hasOverrides'] );
		$override_blocks = is_array( $overrides['overrideAttributes'] ?? null ) ? $overrides['overrideAttributes'] : [];

		if ( $custom_context && $has_overrides && [] !== $override_blocks ) {
			return 0.90;
		}
		if ( $has_overrides ) {
			return 0.70;
		}

		return 0.55;
	}

	private static function clamp( float $score ): float {
		return max( 0.0, min( 1.0, $score ) );
	}
}
```

- [ ] **Step 5: Wire component scores into `PatternAbilities`**

Add:

```php
use FlavorAgent\Patterns\PatternComponentScorer;
```

When building each candidate ranking hint, add a context array:

```php
$component_context = [
	'rootBlock'            => $root_block,
	'nearbySiblings'       => $nearby_siblings,
	'templatePartArea'     => $template_part_area,
	'isCustomBlockContext' => $is_custom_block_context,
];
$component_scores = PatternComponentScorer::score(
	(float) $candidate['score'],
	is_array( $candidate['payload'] ?? null ) ? $candidate['payload'] : [],
	$component_context
);
$ranking_hint['componentScores'] = $component_scores;
$candidate['rankingScore']      = (float) $component_scores['blended'] + (float) ( $ranking_hint['bonus'] ?? 0.0 );
```

When building `sourceSignals`, add:

```php
$source_signals[] = 'component_ranking_v1';
```

Ensure `build_candidates_for_llm()` already passes `rankingHints`; after this change the JSON candidate payload includes `componentScores` without another field.

- [ ] **Step 6: Run focused ranking tests**

Run:

```bash
composer run test:php -- --filter 'RankingContractTest|PatternAbilitiesTest|PatternIndexTest'
```

Expected: PASS.

---

## Task 4: Add Cross-Surface Design Validator Signals

**Files:**
- Create: `inc/Support/RecommendationDesignValidator.php`
- Modify: `inc/Support/RecommendationContextScorer.php`
- Modify: `inc/Support/RankingContract.php`
- Modify: `inc/LLM/Prompt.php`
- Modify: `inc/LLM/StylePrompt.php`
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Modify: `tests/phpunit/RecommendationContextScorerTest.php`
- Modify: `tests/phpunit/StylePromptTest.php`
- Modify: `tests/phpunit/PromptValidationReasonsTest.php`
- Modify: `tests/phpunit/TemplatePromptTest.php`
- Modify: `tests/phpunit/TemplatePartPromptTest.php`

- [ ] **Step 1: Write failing scorer tests for quality signals**

Add to `tests/phpunit/RecommendationContextScorerTest.php`:

```php
public function test_quality_signals_boost_preset_contrast_safe_suggestions(): void {
	$result = RecommendationContextScorer::score(
		[
			'suggestion' => [
				'label'          => 'Use the contrast preset',
				'qualitySignals' => [
					'contrastPreserved' => true,
					'presetBacked'      => true,
					'noOp'              => false,
				],
			],
			'context'    => [],
			'prompt'     => 'Improve readability',
		]
	);

	$this->assertGreaterThanOrEqual( 0.60, $result['score'] );
	$this->assertGreaterThan( 0.55, $result['evidence']['accessibility_fit'] );
	$this->assertGreaterThan( 0.55, $result['evidence']['native_preset_fit'] );
	$this->assertArrayNotHasKey( 'duplicate_or_noop', $result['penalties'] );
}

public function test_quality_signals_penalize_failed_contrast_and_no_op(): void {
	$result = RecommendationContextScorer::score(
		[
			'suggestion' => [
				'label'          => 'Keep the same low contrast color',
				'qualitySignals' => [
					'contrastPreserved' => false,
					'presetBacked'      => false,
					'noOp'              => true,
				],
			],
			'context'    => [],
			'prompt'     => 'Improve readability',
		]
	);

	$this->assertLessThan( 0.55, $result['score'] );
	$this->assertArrayHasKey( 'failed_contrast', $result['penalties'] );
	$this->assertArrayHasKey( 'duplicate_or_noop', $result['penalties'] );
}
```

- [ ] **Step 2: Run scorer tests and confirm failure**

Run:

```bash
composer run test:php -- --filter 'RecommendationContextScorerTest::test_quality_signals'
```

Expected: FAIL because the new evidence/penalty keys do not exist.

- [ ] **Step 3: Create `RecommendationDesignValidator`**

Create `inc/Support/RecommendationDesignValidator.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RecommendationDesignValidator {

	/**
	 * @param array<string, mixed> $suggestion
	 * @param array<string, mixed> $context
	 * @return array{qualitySignals: array<string, mixed>, validationReasons: array<int, array<string, string>>}
	 */
	public static function analyze( array $suggestion, array $context ): array {
		$operations = is_array( $suggestion['operations'] ?? null ) ? $suggestion['operations'] : [];
		$signals    = [
			'contrastPreserved'  => self::contrast_preserved( $suggestion ),
			'presetBacked'       => self::uses_preset_values( $operations, $suggestion ),
			'typographyReadable' => self::typography_readable( $operations ),
			'spacingScaleFit'    => self::spacing_scale_fit( $operations ),
			'noOp'               => self::is_no_op( $suggestion, $context ),
			'responsiveSane'     => self::responsive_sane( $operations ),
			'complexityFit'      => self::complexity_fit( $suggestion, $context ),
		];

		$reasons = [];
		if ( false === $signals['contrastPreserved'] ) {
			$reasons[] = [ 'code' => 'failed_contrast', 'severity' => 'error' ];
		}
		if ( false === $signals['presetBacked'] && self::touches_preset_candidate_path( $operations ) ) {
			$reasons[] = [ 'code' => 'raw_value_when_preset_available', 'severity' => 'warning' ];
		}
		if ( true === $signals['noOp'] ) {
			$reasons[] = [ 'code' => 'duplicate_or_noop', 'severity' => 'warning' ];
		}
		if ( false === $signals['responsiveSane'] ) {
			$reasons[] = [ 'code' => 'responsive_visibility_risk', 'severity' => 'warning' ];
		}
		if ( false === $signals['complexityFit'] ) {
			$reasons[] = [ 'code' => 'excessive_visual_complexity', 'severity' => 'warning' ];
		}

		return [
			'qualitySignals'    => $signals,
			'validationReasons' => ValidationReason::normalize( $reasons ),
		];
	}

	private static function contrast_preserved( array $suggestion ): ?bool {
		foreach ( (array) ( $suggestion['validationReasons'] ?? [] ) as $reason ) {
			if ( is_array( $reason ) && 'failed_contrast' === sanitize_key( (string) ( $reason['code'] ?? '' ) ) ) {
				return false;
			}
		}

		return null;
	}

	private static function uses_preset_values( array $operations, array $suggestion ): bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$value_type = is_string( $operation['valueType'] ?? null ) ? $operation['valueType'] : '';
			$value      = is_string( $operation['value'] ?? null ) ? $operation['value'] : '';
			if ( 'preset' === $value_type || str_starts_with( $value, 'var:preset|' ) || str_starts_with( $value, 'var(--wp--preset--' ) ) {
				return true;
			}
		}

		return ! self::touches_preset_candidate_path( $operations ) && empty( $suggestion['attributeUpdates'] );
	}

	private static function touches_preset_candidate_path( array $operations ): bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			if ( str_contains( $path, 'color' ) || str_contains( $path, 'spacing' ) || str_contains( $path, 'typography' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function typography_readable( array $operations ): ?bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path  = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			$value = is_scalar( $operation['value'] ?? null ) ? (string) $operation['value'] : '';
			if ( str_contains( $path, 'typography.fontSize' ) && preg_match( '/^([0-9.]+)px$/', $value, $matches ) ) {
				$size = (float) ( $matches[1] ?? 0 );
				return $size >= 12.0 && $size <= 96.0;
			}
		}

		return null;
	}

	private static function spacing_scale_fit( array $operations ): ?bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path  = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			$value = is_scalar( $operation['value'] ?? null ) ? (string) $operation['value'] : '';
			if ( str_contains( $path, 'spacing' ) && preg_match( '/^[0-9.]+(px|rem|em)$/', $value ) ) {
				return false;
			}
		}

		return null;
	}

	private static function is_no_op( array $suggestion, array $context ): bool {
		$updates = $suggestion['attributeUpdates'] ?? null;
		$current = $context['currentState']['attributes'] ?? $context['block']['attributes'] ?? null;

		return is_array( $updates ) && is_array( $current ) && $updates === $current;
	}

	private static function responsive_sane( array $operations ): ?bool {
		foreach ( $operations as $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}

			$path = is_array( $operation['path'] ?? null ) ? implode( '.', array_map( 'strval', $operation['path'] ) ) : '';
			if ( str_contains( $path, 'visibility' ) || str_contains( $path, 'display' ) ) {
				return false;
			}
		}

		return null;
	}

	private static function complexity_fit( array $suggestion, array $context ): ?bool {
		$block_count = isset( $suggestion['contentBlockCount'] ) && is_numeric( $suggestion['contentBlockCount'] )
			? (int) $suggestion['contentBlockCount']
			: null;
		$role = sanitize_key( (string) ( $context['designSemantics']['sectionRole'] ?? $context['templatePartArea'] ?? '' ) );

		if ( null === $block_count ) {
			return null;
		}

		return ! ( $block_count > 10 && in_array( $role, [ 'header', 'footer', 'sidebar' ], true ) );
	}
}
```

- [ ] **Step 4: Extend ranking evidence/penalty keys**

In both `RankingContract` and `RecommendationContextScorer`, add evidence keys:

```php
'contrast_preserved',
'preset_adherence',
'spacing_scale_fit',
'typography_readability',
'responsive_sanity',
'complexity_fit',
```

Add penalty keys:

```php
'failed_contrast',
'raw_value_when_preset_available',
'duplicate_or_noop',
'responsive_visibility_risk',
'excessive_visual_complexity',
```

In `RecommendationContextScorer::score()`, after existing accessibility/design evidence, read:

```php
$quality = self::map( $suggestion['qualitySignals'] ?? [] );
$evidence['contrast_preserved']     = array_key_exists( 'contrastPreserved', $quality ) ? ( ! empty( $quality['contrastPreserved'] ) ? 0.85 : 0.25 ) : 0.55;
$evidence['preset_adherence']       = array_key_exists( 'presetBacked', $quality ) ? ( ! empty( $quality['presetBacked'] ) ? 0.85 : 0.35 ) : 0.55;
$evidence['spacing_scale_fit']      = array_key_exists( 'spacingScaleFit', $quality ) ? ( false === $quality['spacingScaleFit'] ? 0.35 : 0.75 ) : 0.55;
$evidence['typography_readability'] = array_key_exists( 'typographyReadable', $quality ) ? ( false === $quality['typographyReadable'] ? 0.35 : 0.75 ) : 0.55;
$evidence['responsive_sanity']      = array_key_exists( 'responsiveSane', $quality ) ? ( false === $quality['responsiveSane'] ? 0.35 : 0.75 ) : 0.55;
$evidence['complexity_fit']         = array_key_exists( 'complexityFit', $quality ) ? ( false === $quality['complexityFit'] ? 0.35 : 0.75 ) : 0.55;

if ( array_key_exists( 'contrastPreserved', $quality ) && false === $quality['contrastPreserved'] ) {
	$penalties['failed_contrast'] = 0.20;
}
if ( array_key_exists( 'presetBacked', $quality ) && false === $quality['presetBacked'] ) {
	$penalties['raw_value_when_preset_available'] = 0.12;
}
if ( ! empty( $quality['noOp'] ) ) {
	$penalties['duplicate_or_noop'] = 0.20;
}
if ( array_key_exists( 'responsiveSane', $quality ) && false === $quality['responsiveSane'] ) {
	$penalties['responsive_visibility_risk'] = 0.15;
}
if ( array_key_exists( 'complexityFit', $quality ) && false === $quality['complexityFit'] ) {
	$penalties['excessive_visual_complexity'] = 0.12;
}
```

Rebalance `EVIDENCE_WEIGHTS` so all weights total `1.0`; reduce `prompt_match`, `operation_fit`, and `supports_fit` slightly to make room for the six new quality keys.

- [ ] **Step 5: Attach validator signals in prompt parsers**

For each parser after it has built the normalized `$entry` / `$suggestion` and existing `validationReasons`, call:

```php
$design_validation = RecommendationDesignValidator::analyze( $entry, $context );
$entry['qualitySignals'] = $design_validation['qualitySignals'];
$entry['validationReasons'] = ValidationReason::normalize(
	array_merge(
		is_array( $entry['validationReasons'] ?? null ) ? $entry['validationReasons'] : [],
		$design_validation['validationReasons']
	)
);
```

Use each file's local variable names:

- `Prompt.php`: pass the current ranking context/context group available to `score_contextual_recommendation()`.
- `StylePrompt.php`: preserve existing `StyleContrastValidator` result first, then merge design validator output so `failed_contrast` remains authoritative.
- `TemplatePrompt.php` and `TemplatePartPrompt.php`: pass the parser `$context`; these surfaces get no-op, complexity, and preset signals where operations expose enough data.

- [ ] **Step 6: Run focused validator suites**

Run:

```bash
composer run test:php -- --filter 'RecommendationContextScorerTest|StylePromptTest|PromptValidationReasonsTest|TemplatePromptTest|TemplatePartPromptTest'
```

Expected: PASS.

---

## Task 5: Add Pattern Relevance Fixtures And Gate Component Ranking

**Files:**
- Modify: `tests/phpunit/fixtures/recommendation-evaluation-expanded-fixtures.php`
- Modify: `tests/phpunit/RecommendationEvaluationTest.php`
- Modify: `tests/phpunit/PatternAbilitiesTest.php`

- [ ] **Step 1: Add pattern relevance fixture cases**

Extend `recommendation-evaluation-expanded-fixtures.php` with:

```php
'footer_rejects_dense_pricing_pattern' => [
	'surface'              => 'pattern',
	'expectedRelevantKeys' => [ 'theme/footer-links', 'theme/footer-cta' ],
	'suggestions'          => [
		[
			'name'    => 'theme/footer-links',
			'ranking' => [
				'rankingHint' => [
					'componentScores' => [
						'semantic'  => 0.72,
						'structure' => 0.82,
						'design'    => 0.84,
						'area'      => 0.91,
						'override'  => 0.55,
						'blended'   => 0.78,
					],
				],
			],
		],
		[
			'name'    => 'theme/pricing-columns',
			'ranking' => [
				'rankingHint' => [
					'componentScores' => [
						'semantic'  => 0.80,
						'structure' => 0.42,
						'design'    => 0.38,
						'area'      => 0.25,
						'override'  => 0.55,
						'blended'   => 0.57,
					],
				],
			],
		],
	],
],
'custom_block_context_prefers_override_ready_pattern' => [
	'surface'              => 'pattern',
	'expectedRelevantKeys' => [ 'theme/custom-card-overrides' ],
	'suggestions'          => [
		[
			'name'    => 'theme/custom-card-overrides',
			'ranking' => [
				'rankingHint' => [
					'componentScores' => [
						'semantic'  => 0.70,
						'structure' => 0.68,
						'design'    => 0.74,
						'area'      => 0.55,
						'override'  => 0.92,
						'blended'   => 0.71,
					],
				],
			],
		],
	],
],
```

- [ ] **Step 2: Update expected metric totals**

Update the expected values in `test_expanded_quality_metrics_match_recorded_fixture_output()` to include the two new fixtures. The expected `topThreeRelevanceRate` must be `0.8333` or higher. Keep `invalidOperationRate` unchanged unless these fixtures add rejected operations.

- [ ] **Step 3: Run expanded evaluation and pattern tests**

Run:

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|PatternAbilitiesTest'
```

Expected: PASS.

---

## Task 6: Documentation And Verification Closeout

**Files:**
- Modify: `improving-levers.md`
- Modify: `docs/reference/current-open-work.md`
- Modify: `docs/reference/pattern-recommendation-debugging.md`
- Modify: `docs/features/pattern-recommendations.md`

- [ ] **Step 1: Update roadmap status after code lands**

In `improving-levers.md`, update Phase 6 and Phase 7 checkboxes only after the matching tests pass. Keep learning-loop phases unchecked.

- [ ] **Step 2: Update the open-work tracker**

In `docs/reference/current-open-work.md`, move or narrow these rows:

- `Pattern relevance and metadata/component ranking`
- `Remaining design validators`
- `Expanded evaluation harness`

If all acceptance criteria pass, replace them with any remaining follow-up such as provider-to-provider ranking stability or browser-only validation evidence. If any metric remains absent, leave the row open with the exact missing metric.

- [ ] **Step 3: Document pattern ranking diagnostics**

In `docs/reference/pattern-recommendation-debugging.md`, add a section named `Component ranking diagnostics` with:

```markdown
Pattern recommendations expose `ranking.rankingHint.componentScores` when component ranking is active. Scores are bounded 0-1 values:

- `semantic`: retrieval similarity from the selected pattern backend
- `structure`: block and layout compatibility with the insertion root
- `design`: design metadata compatibility with the surrounding context
- `area`: template-part area fit
- `override`: Pattern Overrides fit for custom-block or content-override contexts
- `blended`: weighted component score before LLM reranking and contextual ranking
```

- [ ] **Step 4: Run closeout gates**

Run:

```bash
composer run test:php -- --filter 'RecommendationEvaluationTest|PatternIndexTest|PatternAbilitiesTest|RankingContractTest|RecommendationContextScorerTest|StylePromptTest|PromptValidationReasonsTest|TemplatePromptTest|TemplatePartPromptTest'
npm run test:unit -- --runInBand src/patterns/__tests__/PatternRecommender.test.js
npm run check:docs
git diff --check
```

Expected: PASS for all commands. If the JS test fails from existing unrelated worktree changes, capture the failure and rerun only after isolating the unrelated diff.

---

## Self-Review Notes

- Spec coverage: the plan covers pattern component ranking, missing deterministic validators, and expanded metrics before tuning. It deliberately excludes docs fingerprint split, adapted preview, and learning reports.
- Placeholder scan: this plan contains no unresolved marker text, no open-ended "add tests" step without a test shape, and no unspecified file targets.
- Type consistency: `designMetadata`, `componentScores`, `qualitySignals`, `validationReasons`, and `rankingHint` names match current repository naming conventions and the target roadmap text.
