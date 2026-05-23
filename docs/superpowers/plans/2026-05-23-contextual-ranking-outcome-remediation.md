# Contextual Ranking Outcome Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended for parallel test/implementation work) or `superpowers:executing-plans` to implement this plan task-by-task. Track each step by updating the checkbox in this document before moving to the next step.

## Goal

Fully resolve the two confirmed regressions found in the uncommitted review:

1. Style Book no-op scoring fails to detect real no-op `set_block_styles` operations when the target path contains camelCase keys such as `fontSize`.
2. Pattern recommendation contextual ranking metadata is produced by PHP but dropped before recommendation outcome diagnostics are persisted.

The complete solution must prove each regression with a failing test or equivalent reproducible proof first, then implement the smallest durable fix that preserves existing contracts and privacy boundaries.

## Confirmed Findings

### Finding 1: Style Book Block Style No-Op Scoring Misses CamelCase Paths

**Root issue:** `RecommendationContextScorer::style_operations_are_possible_no_op()` compares Style Book operation paths against the wrong current-style branch for `set_block_styles`, and its path normalization lowercases camelCase style keys through `sanitize_key()`. Real block style operations are applied under `styles.blocks[blockName]`, while generated Style Book operations use paths such as `["typography","fontSize"]`. The scorer currently treats that as `typography.fontsize`, so it cannot match the existing `fontSize` value.

**Proof already collected:** The following one-off PHP proof produced `[]` for penalties even though the proposed operation exactly matches the current block style value:

```bash
php -r 'define("FLAVOR_AGENT_TESTS_RUNNING", true); require "tests/phpunit/bootstrap.php"; $r = \FlavorAgent\Support\RecommendationContextScorer::score(["surface"=>"style-book","suggestion"=>["operations"=>[["type"=>"set_block_styles","blockName"=>"core/paragraph","path"=>["typography","fontSize"],"value"=>"var:preset|font-size|large"]]],"context"=>["styleContext"=>["currentConfig"=>["styles"=>["blocks"=>["core/paragraph"=>["typography"=>["fontSize"=>"var:preset|font-size|large"]]]]]]]]); echo json_encode($r["penalties"]),"\n";'
```

Expected: `{"possible_no_op":0.25}`.

Actual: `[]`.

**Existing contract evidence:**

- `src/utils/style-operations.js` applies `set_block_styles` under `[ 'blocks', blockName, ...operation.path ]`.
- `inc/LLM/StylePrompt.php` instructs Style Book `set_block_styles` operations to use paths like `["typography","fontSize"]`.
- `inc/Support/RecommendationContextScorer.php` currently normalizes style path segments with `sanitize_key()`, which changes `fontSize` into `fontsize`.

### Finding 2: Pattern Ranking Metadata Is Dropped Before Diagnostics

**Root issue:** `PatternAbilities` returns contextual `ranking` metadata on each pattern recommendation, but `PatternRecommender` builds recommendation outcome payloads that include only IDs, counts, target, and top keys. It does not pass the recommendation list or selected recommendation object into the outcome writer. The generic outcome summary helper also does not inspect `recommendations`, so even a pattern payload containing recommendations would not generate a `rankingSet`.

**Proof already collected:** Static trace through the current code shows the metadata loss point:

- `inc/Abilities/PatternAbilities.php` includes `ranking` on each pattern recommendation.
- `src/patterns/PatternRecommender.js` builds shown/inserted outcome payloads without `rankingSet` or `suggestion`.
- `src/store/recommendation-outcomes.js` only serializes aggregate `rankingSet` when it is supplied, or per-event `ranking` when `payload.suggestion.ranking` exists.
- `getRecommendationListsFromPayload()` only inspects `settings`, `styles`, `block`, and `suggestions`; it ignores `recommendations`.

**Expected diagnostic behavior:** Pattern shelf `shown` events should persist a privacy-safe aggregate `rankingSet`, and selected/inserted pattern events should persist the selected pattern ranking snapshot.

**Actual diagnostic behavior:** Pattern recommendation outcome rows are written without the contextual ranking metadata needed to inspect whether contextual ranking affected ordering.

## Files To Change

- `inc/Support/RecommendationContextScorer.php`
- `tests/phpunit/RecommendationContextScorerTest.php`
- `src/store/recommendation-outcomes.js`
- `src/store/__tests__/recommendation-outcomes.test.js`
- `src/patterns/PatternRecommender.js`
- `src/patterns/__tests__/PatternRecommender.test.js`

No generated files in `build/` or release artifacts in `dist/` should be edited.

## Implementation Plan

### 1. Prove and Fix Style Book Block Style No-Op Scoring

- [x] Add a failing PHPUnit regression test in `tests/phpunit/RecommendationContextScorerTest.php`.

Add a test named `test_style_book_no_op_detection_reads_block_scoped_current_styles_with_camel_case_paths`.

The test must cover all three behaviors:

- An exact `set_block_styles` match under `styles.blocks[blockName]` with `path => [ 'typography', 'fontSize' ]` adds `possible_no_op`.
- A different proposed value for the same block and path does not add `possible_no_op`.
- The same path and value under a different missing block does not add `possible_no_op`.

Use this test shape:

```php
public function test_style_book_no_op_detection_reads_block_scoped_current_styles_with_camel_case_paths(): void {
	$context = array(
		'styleContext' => array(
			'currentConfig' => array(
				'styles' => array(
					'blocks' => array(
						'core/paragraph' => array(
							'typography' => array(
								'fontSize' => 'var:preset|font-size|large',
							),
						),
					),
				),
			),
		),
	);

	$exact = RecommendationContextScorer::score(
		array(
			'surface'    => 'style-book',
			'suggestion' => array(
				'operations' => array(
					array(
						'type'      => 'set_block_styles',
						'blockName' => 'core/paragraph',
						'path'      => array( 'typography', 'fontSize' ),
						'value'     => 'var:preset|font-size|large',
					),
				),
			),
			'context'    => $context,
		)
	);

	$different = RecommendationContextScorer::score(
		array(
			'surface'    => 'style-book',
			'suggestion' => array(
				'operations' => array(
					array(
						'type'      => 'set_block_styles',
						'blockName' => 'core/paragraph',
						'path'      => array( 'typography', 'fontSize' ),
						'value'     => 'var:preset|font-size|small',
					),
				),
			),
			'context'    => $context,
		)
	);

	$missing_block = RecommendationContextScorer::score(
		array(
			'surface'    => 'style-book',
			'suggestion' => array(
				'operations' => array(
					array(
						'type'      => 'set_block_styles',
						'blockName' => 'core/heading',
						'path'      => array( 'typography', 'fontSize' ),
						'value'     => 'var:preset|font-size|large',
					),
				),
			),
			'context'    => $context,
		)
	);

	$this->assertSame( 0.25, $exact['penalties']['possible_no_op'] );
	$this->assertArrayNotHasKey( 'possible_no_op', $different['penalties'] );
	$this->assertArrayNotHasKey( 'possible_no_op', $missing_block['penalties'] );
}
```

- [x] Run the targeted proof command and confirm it fails before implementation.

```bash
composer run test:php -- --filter RecommendationContextScorerTest
```

The new test should fail because `possible_no_op` is missing from the exact-match result.

- [x] Update `inc/Support/RecommendationContextScorer.php` so lookup paths preserve real style keys.

Implement a path helper for current-style lookup that preserves case while still rejecting invalid segments:

```php
private static function normalize_style_lookup_path( mixed $path ): array {
	$segments = self::normalize_path_segments( $path );
	if ( array() !== $segments && 'styles' === $segments[0] ) {
		array_shift( $segments );
	}

	return array_values(
		array_filter(
			array_map(
				static function ( $segment ): string {
					return trim( sanitize_text_field( (string) $segment ) );
				},
				$segments
			),
			static fn( string $segment ): bool => '' !== $segment
		)
	);
}
```

If `normalize_path_segments()` currently lowercases the segments before this helper can preserve them, split the existing behavior into two helpers:

- A support-inventory helper that may continue to use lowercase canonical keys.
- A current-style lookup helper that preserves the original segment case.

- [x] Update the no-op comparison to use the correct current-style branch for block styles.

For `set_block_styles`:

1. Read `blockName` from the operation.
2. Require a non-empty block name.
3. Read from `$current_styles['blocks'][ $block_name ]`.
4. Traverse the preserved lookup path inside that block branch.
5. Compare the current value to the operation value.

For global `set_styles`:

1. Read from `$current_styles`.
2. Traverse the preserved lookup path.
3. Compare the current value to the operation value.

The comparison must not mutate the existing support inventory logic or capability scoring. The fix is only for detecting whether a proposed style operation is already represented by the current config.

- [x] Re-run the targeted PHPUnit proof and confirm the new test passes.

```bash
composer run test:php -- --filter RecommendationContextScorerTest
```

### 2. Prove and Fix Pattern Ranking Outcome Persistence

- [x] Add a failing store unit test in `src/store/__tests__/recommendation-outcomes.test.js`.

Add coverage that proves the generic outcome summary can derive a `rankingSet` from a `recommendations` list:

```js
const payload = {
	recommendationOutcome: {
		recommendationSetId: 'pattern:abc',
		sourceRequestSignature: 'source:sig',
	},
	recommendations: [
		{
			name: 'theme/hero',
			ranking: {
				contextScore: 0.91,
				blendedScore: 0.88,
				rankingVersion: 'contextual-ranking-v1',
			},
		},
	],
};

const summary = getRecommendationOutcomeSummaryFromPayload( payload );

expect( summary.rankingSet ).toEqual( [
	expect.objectContaining( {
		suggestionKey: 'theme/hero',
		ranking: expect.objectContaining( {
			contextScore: 0.91,
			blendedScore: 0.88,
			rankingVersion: 'contextual-ranking-v1',
		} ),
	} ),
] );
```

Before implementation, this must fail because `getRecommendationListsFromPayload()` ignores `recommendations`.

- [x] Update `src/store/recommendation-outcomes.js` to include pattern recommendation lists.

Change `getRecommendationListsFromPayload()` so it also returns `payload.recommendations` when that value is an array. Keep the existing list order for current surfaces, then append `recommendations` to avoid altering existing precedence:

```js
const listCandidates = [
	payload?.settings,
	payload?.styles,
	payload?.block,
	payload?.suggestions,
	payload?.recommendations,
];
```

Do not broaden ranking serialization beyond the existing `normalizeRankingSnapshot()` allowlist. This keeps diagnostics privacy-safe and prevents generated copy, pattern descriptions, or raw context payloads from being written into outcome metadata.

- [x] Add failing Pattern Recommender tests in `src/patterns/__tests__/PatternRecommender.test.js`.

Add or extend tests to prove both outcome paths:

1. A visible pattern shelf `shown` event records `rankingSet` derived from the currently displayed pattern recommendations.
2. A selected or inserted pattern event passes the selected recommendation as `suggestion`, allowing the outcome writer to persist the selected ranking snapshot.

Use assertions shaped like:

```js
expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
	expect.objectContaining( {
		event: 'shown',
		rankingSet: [
			expect.objectContaining( {
				suggestionKey: 'theme/hero',
				ranking: expect.objectContaining( {
					contextScore: 0.91,
					blendedScore: 0.88,
				} ),
			} ),
		],
	} )
);
```

For the insert or selection path:

```js
expect( mockRecordRecommendationOutcome ).toHaveBeenCalledWith(
	expect.objectContaining( {
		event: 'pattern_inserted_from_shelf',
		suggestion: expect.objectContaining( {
			name: 'theme/hero',
			ranking: expect.objectContaining( {
				contextScore: 0.91,
			} ),
		} ),
	} )
);
```

Before implementation, these tests must fail because `buildPatternOutcomePayload()` does not include `rankingSet` or `suggestion`.

- [x] Update `src/patterns/PatternRecommender.js` to preserve ranking metadata in outcome payloads.

Import `getRecommendationOutcomeSummaryFromPayload` from `src/store/recommendation-outcomes.js` if it is not already imported.

Create a memoized pattern recommendation outcome summary from the current displayed pattern recommendations:

```js
const patternOutcomeSummary = useMemo(
	() =>
		getRecommendationOutcomeSummaryFromPayload( {
			recommendationOutcome: {
				recommendationSetId: patternRecommendationSetId,
				sourceRequestSignature: patternSourceRequestSignature,
			},
			recommendations: recommendedPatterns.map(
				( { recommendation } ) => recommendation
			),
		} ),
	[
		patternRecommendationSetId,
		patternSourceRequestSignature,
		recommendedPatterns,
	]
);
```

Update `buildPatternOutcomePayload()` so:

- `shown` events include `rankingSet` from `patternOutcomeSummary.rankingSet` when present.
- The payload retains existing `recommendationSetId`, `sourceRequestSignature`, `resultCount`, `topSuggestionKeys`, `target`, and `details`.
- Events tied to one pattern include `suggestion: recommendation`.
- Existing `patternKey`, `patternTitle`, `rank`, and `target` fields are preserved.

The payload builder should continue to use the existing recommendation key helpers so the persisted identifiers remain stable across pattern names, slugs, and titles.

- [x] Confirm no duplicate or stale shown events are introduced.

Inspect the existing shown-event effect dependencies after adding `patternOutcomeSummary`. The effect should still fire once for a stable displayed result set and should not loop because of newly created arrays. If the current `recommendedPatterns` reference is not stable enough, derive a stable memo dependency from the existing pattern recommendation set ID, source signature, and top suggestion keys rather than raw objects.

- [x] Re-run the focused JS tests and confirm all new tests pass.

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/recommendation-outcomes.test.js src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

### 3. Final Verification

- [x] Run PHP scorer coverage.

```bash
composer run test:php -- --filter RecommendationContextScorerTest
```

- [x] Run focused recommendation outcome and pattern UI coverage.

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/recommendation-outcomes.test.js src/patterns/__tests__/PatternRecommender.test.js --runInBand
```

- [x] Run the broader focused suites used during review to catch cross-surface regressions.

```bash
composer run test:php -- --filter 'PromptRulesTest|StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|PatternAbilitiesTest|RecommendationOutcomeTest|RankingContractTest'
```

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/recommendation-outcomes.test.js src/store/__tests__/activity-history.test.js src/components/__tests__/AIActivitySection.test.js src/patterns/__tests__/PatternRecommender.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js --runInBand
```

- [x] Run lint and docs checks.

```bash
git diff --check
npm run lint:js
composer run lint:php
npm run check:docs
```

- [x] Run the aggregate non-E2E verifier.

```bash
npm run verify -- --skip-e2e
```

- [x] Record any unavailable browser or local WordPress harness as an explicit blocker or waiver.

Playwright E2E was not run. This is recorded as a release-gate waiver below, not as browser evidence. The aggregate verifier was run with `--skip-e2e` and recorded `e2e-playground` and `e2e-wp70` as skipped in `output/verify/summary.json`.

Correction (2026-05-23): an earlier draft of this section claimed E2E could be skipped because the remediation changes "diagnostic outcome metadata only, not user-visible pattern UI behavior." That justification is not accurate for the change set that landed in this dirty tree. Recorded as an explicit waiver against `docs/reference/cross-surface-validation-gates.md` Gate 5 (shared UI taxonomy and mode) and Gate 7 (multi-surface release matrix):

- User-visible UI changes in scope of this remediation: `src/patterns/PatternRecommender.js` now creates two new pattern-insertion failure snackbars (`insert_blocks_exception`, `insert_blocks_noop`) so users see why a pattern they accepted was not actually inserted at the target. These notices ride on the existing `inserter-notice` snackbar slot and the existing `createErrorNotice` channel, so they share styling, dismissal, and accessibility behavior with the other pre-existing pattern inserter notices — but they are new behavior the user will observe and should be exercised against `npm run test:e2e:playground`.
- Related user-visible UI changes outside this remediation's stated scope but present in the same dirty tree: `src/templates/TemplateRecommender.js` and `src/template-parts/TemplatePartRecommender.js` now render a single collapsible "Recommendations" lane and move the explanation prose into a `<details>` summary, dropping the previous "Review" plus "Manual ideas" split, starter prompts, secondary guidance, and `AIAdvisorySection`. These changes are tracked separately from this plan and own their own Gate 5/Gate 7 evidence on the relevant surface, but they are flagged here because they will be part of the same release matrix and they invalidate the earlier "diagnostic metadata only" rationale for the dirty tree as a whole.

Browser harness availability for the recorded waiver:

- `npm run test:e2e:playground` is the nearest harness for the pattern inserter UI changes and was not executed in this remediation cycle.
- `npm run test:e2e:wp70` is the nearest harness for the template/template-part panel layout changes. The WP 7.0 harness was not executed in this remediation cycle.

Required follow-up before release sign-off (do not treat the waiver as a permanent pass):

- Run `npm run test:e2e:playground` and assert the pattern inserter snackbar surface still reports `passed/skipped/failed` counts consistent with the 2026-04-22 baseline in `STATUS.md`, with the two new `inserter-notice` failure paths exercised by either an existing or a new smoke step that intentionally hits `insert_blocks_exception` / `insert_blocks_noop`.
- Run `npm run test:e2e:wp70` and assert the template and template-part Site Editor smokes still pass against the restructured single-lane "Recommendations" layout, or record the exact failure with a follow-up issue.
- If either harness is unavailable at follow-up time, re-record the specific environment blocker (for example "Docker not on PATH on host X") rather than re-using the prior "diagnostic metadata only" rationale.

The aggregate `--skip-e2e` verifier pass is *not* a substitute for the above and should not be cited as Gate 5 / Gate 7 evidence for the user-visible behavior changes above.

## Completion Criteria

The work is complete only when all of the following are true:

- Style Book exact-match `set_block_styles` operations with camelCase paths produce `possible_no_op`.
- Different values and missing block branches do not produce false-positive no-op penalties.
- Pattern `shown` recommendation outcomes include a privacy-safe aggregate `rankingSet`.
- Pattern selected/inserted recommendation outcomes include the selected recommendation ranking snapshot.
- Existing recommendation outcome contracts for settings, styles, block, templates, and template parts remain passing.
- Focused PHP, focused JS, lint, docs, and aggregate non-E2E verification commands pass or have a recorded environment blocker.
