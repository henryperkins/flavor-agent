# Learning Attribution Join Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Phase 8's server-minted generation join contract across existing request diagnostic, recommendation outcome, apply, and undo activity rows.

**Architecture:** PHP owns generation identity in `RecommendationAbilityExecution` and normalizes JS-created outcome rows in `RecommendationOutcome`. JS copies the returned `learningAttribution` object through decorated recommendation payloads, outcome diagnostics, and apply-row recommendation identity without changing ranking or UI behavior.

**Tech Stack:** WordPress plugin PHP, PHPUnit, Gutenberg data-store JavaScript, Jest through `@wordpress/scripts`.

---

## Files

- Modify: `inc/Abilities/RecommendationAbilityExecution.php`
- Modify: `inc/Activity/RecommendationOutcome.php`
- Modify: `src/store/recommendation-outcomes.js`
- Modify: `src/store/activity-undo.js` only if current undo transition coverage does not already preserve request payloads
- Test: `tests/phpunit/RecommendationAbilityExecutionTest.php`
- Test: `tests/phpunit/RecommendationOutcomeTest.php`
- Test: `tests/phpunit/ActivityRepositoryTest.php` or closest existing repository/serializer test
- Test: `src/store/__tests__/recommendation-outcomes.test.js`
- Test: `src/store/__tests__/activity-undo.test.js` or `src/store/__tests__/store-actions.test.js`
- Docs: `improving-levers.md`
- Docs: `docs/reference/current-open-work.md`

## Task 1: PHP Attribution Metadata

- [x] Add failing tests in `tests/phpunit/RecommendationAbilityExecutionTest.php` proving a successful non-signature recommendation response contains `requestMeta.learningAttribution.generationId`, `guidelineVersion`, provider/model, and validation vocabulary version, and that a request diagnostic row stores the same object under `request.learningAttribution`.

Run:

```bash
composer run test:php -- --filter RecommendationAbilityExecutionTest
```

Expected: fail because `learningAttribution` does not exist.

- [x] Implement a private `build_learning_attribution()` helper in `inc/Abilities/RecommendationAbilityExecution.php`.

Implementation shape:

```php
private static function build_learning_attribution( string $surface, string $ability_name, array $request_meta, FlavorAgentRequestTag $request_tag ): array {
	$provider = sanitize_text_field( (string) ( $request_meta['provider'] ?? $request_meta['providerLabel'] ?? '' ) );
	$model    = sanitize_text_field( (string) ( $request_meta['model'] ?? '' ) );

	return array_filter(
		[
			'generationId'                 => self::generate_generation_id( $surface, $request_tag ),
			'guidelineVersion'             => Guidelines::version_id(),
			'provider'                     => '' !== $provider ? substr( $provider, 0, 191 ) : null,
			'model'                        => '' !== $model ? substr( $model, 0, 191 ) : null,
			'rankingVersion'               => 'contextual-ranking-v1',
			'validationVocabularyVersion'  => ValidationReason::VERSION,
		],
		static fn ( $value ): bool => null !== $value && '' !== $value
	);
}
```

- [x] Attach that object in `append_request_meta()` and `append_request_meta_to_error()` only for non-signature recommendation execution paths, and copy it into `persist_request_diagnostic_activity()` / `persist_request_diagnostic_failure_activity()` request payloads.

- [x] Re-run:

```bash
composer run test:php -- --filter RecommendationAbilityExecutionTest
```

Expected: pass.

## Task 2: PHP Outcome Normalization

- [x] Add failing tests in `tests/phpunit/RecommendationOutcomeTest.php` proving `learningAttribution` with `generationId`, docs fingerprints, provider/model, ranking version, and validation vocabulary version is preserved, unknown fields are dropped, and rows without `generationId` drop the attribution object.

Run:

```bash
composer run test:php -- --filter RecommendationOutcomeTest
```

Expected: fail because the normalizer currently ignores `learningAttribution`.

- [x] Add `normalize_learning_attribution()` to `inc/Activity/RecommendationOutcome.php` and call it from `normalize_entry()`.

Implementation shape:

```php
$learning_attribution = self::normalize_learning_attribution(
	$outcome['learningAttribution'] ?? $entry['learningAttribution'] ?? null
);
if ( [] !== $learning_attribution ) {
	$normalized_outcome['learningAttribution'] = $learning_attribution;
}
```

The helper must allow only the approved keys, require `generationId`, sanitize with `sanitize_text_field()` / `sanitize_key()` as appropriate, and cap string lengths to existing constants.

- [x] Include the normalized object in `$request_recommendation['learningAttribution']`.

- [x] Re-run:

```bash
composer run test:php -- --filter RecommendationOutcomeTest
```

Expected: pass.

## Task 3: JS Outcome Propagation

- [x] Add failing tests in `src/store/__tests__/recommendation-outcomes.test.js` proving `decorateRecommendationPayload()` copies root `requestMeta.learningAttribution` to each suggestion's `recommendationOutcome`, `getRecommendationOutcomeSummaryFromPayload()` returns it, `buildRecommendationOutcomeEntry()` stores it in `after.outcome.learningAttribution`, and `getRecommendationIdentityForApply()` returns it for apply rows.

Run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/recommendation-outcomes.test.js --runInBand
```

Expected: fail because JS does not normalize or copy `learningAttribution`.

- [x] Implement `normalizeLearningAttribution()` in `src/store/recommendation-outcomes.js`, requiring `generationId` and bounding the allowlisted fields.

- [x] Pass the normalized object through `decorateRecommendationPayload()`, `getRecommendationOutcomeSummaryFromPayload()`, `buildRecommendationIdentityFromSuggestion()`, `buildRecommendationOutcomeEntry()`, and `getRecommendationIdentityForApply()`.

- [x] Re-run:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/recommendation-outcomes.test.js --runInBand
```

Expected: pass.

## Task 4: Apply And Undo Preservation

- [x] Add or extend a focused JS test proving a block/template/style apply entry created from a suggestion includes `request.recommendation.learningAttribution`, and undo status updates preserve that request payload.

Run the closest targeted suite:

```bash
npm run test:unit -- --runTestsByPath src/store/__tests__/activity-undo.test.js src/store/__tests__/store-actions.test.js --runInBand
```

Expected: fail only if the propagation is missing or undo rewrite drops request metadata.

- [x] Confirm no `src/store/activity-undo.js` implementation change is needed because undo transitions preserve the existing `request` object. Do not recompute attribution during undo.

- [x] Re-run the same targeted suite.

Expected: pass.

## Task 5: Docs And Open Work Closeout

- [x] Update `improving-levers.md` Phase 8 checkboxes to complete the join-contract bullets and leave Phase 9+ open.

- [x] Update `docs/reference/current-open-work.md` to remove the implementation-candidate row and add a dated status note that Phase 8's attribution join contract shipped without learning reports.

- [x] Run:

```bash
npm run check:docs
git diff --check
```

Expected: pass.

## Task 6: Final Verification

- [x] Run the focused PHP and JS verification bundle:

```bash
composer run test:php -- --filter 'RecommendationAbilityExecution|RecommendationOutcome|ActivityRepository|Serializer'
npm run test:unit -- --runTestsByPath src/store/__tests__/recommendation-outcomes.test.js src/store/__tests__/activity-undo.test.js src/store/__tests__/store-actions.test.js --runInBand
npm run check:docs
git diff --check
```

Expected: all pass. If a broad store-actions suite is too slow or unrelated-red, capture the exact failure and rerun the narrower test that covers the changed helper.
