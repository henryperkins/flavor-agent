# Contextual Ranking Review Remediation Plan

> Status: Archived 2026-06-05. Shipped with the contextual ranking and outcome diagnostics work; retained only as historical execution context.

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` for parallelizable test and implementation work, or `superpowers:executing-plans` if working this plan serially. Follow the checkbox tasks in order, keep each step small, and run the listed verification command before marking a task complete.

**Goal:** Fix all confirmed regressions from the uncommitted contextual-ranking review so block rerank scores, style-surface context scoring, and inline activity history all honor the contracts already documented and tested elsewhere.

**Architecture:** The remediation keeps the current pipeline shape. PHP prompt reranking continues to compute contextual ranking metadata in `Prompt` and normalize it through `RankingContract`. Shared PHP context scoring remains centralized in `RecommendationContextScorer`, with style-surface context read from the normalized `styleContext` payload produced by `StyleAbilities` and consumed by `StylePrompt`. JavaScript activity visibility remains centralized around `activity-history.js`, with recommender components filtering through a shared diagnostic predicate instead of duplicating type checks.

**Tech Stack:** PHP 8.x, PHPUnit, WordPress plugin test bootstrap, React components built on `@wordpress/*`, Jest via `@wordpress/scripts`, existing repo scripts in `composer.json` and `package.json`.

## Confirmed Findings

1. **Block rerank keeps stale primary `ranking.score`.**
   - `inc/LLM/Prompt.php:1123` passes the existing `$ranking` array into `RankingContract::normalize()` after calculating `$computed_score`.
   - `inc/Support/RankingContract.php:45` resolves `$input['score']` before `$defaults['score']`, so stale LLM-provided scores survive while `blendedScore` reflects the new contextual calculation.
   - Review reproduction: a suggestion with existing `ranking.score = 0.9` produced `ranking.score = 0.9` and `ranking.blendedScore = 0.7825` after rerank.

2. **Style contextual ranking ignores style-surface support and no-op context.**
   - `inc/LLM/StylePrompt.php:913` calls `RecommendationContextScorer::score()` with style context, but no execution contract.
   - `inc/Support/RecommendationContextScorer.php:172` only reads `executionContract.styleSupportPaths`, while style payloads expose `context.styleContext.supportedStylePaths` from `inc/Abilities/StyleAbilities.php:321`.
   - `inc/Support/RecommendationContextScorer.php:376` only compares `attributeUpdates` against block/current-state attributes. It does not compare style operations against `context.styleContext.currentConfig.styles`.
   - Review reproduction: a supported `set_styles` operation for `color.background` scored `supports_fit = 0.55` instead of a supported boost, and an exact `set_styles` no-op did not receive `possible_no_op`.

3. **Template inline activity admits recommendation-outcome diagnostics.**
   - `src/templates/TemplateRecommender.js:344` and `src/template-parts/TemplatePartRecommender.js:447` filter only `request_diagnostic` entries before rendering inline activity.
   - `src/store/activity-history.js:1006` also excludes only request diagnostics from `getLatestAppliedActivity()`.
   - Recommendation outcomes are diagnostic rows (`type: 'recommendation_outcome'`) created by `src/store/recommendation-outcomes.js`, automatic outcome recording in `src/store/executable-surfaces.js`, and selection recording in `src/templates/TemplateRecommender.js:752`.
   - Because template activity matching also accepts `document.scopeKey`, shown/selected recommendation outcomes can appear as normal “Recent AI Actions” entries and can become the latest applied activity.

## Task 1: Replace Stale Primary Scores During Block Rerank

- [ ] Add a failing PHPUnit regression test in `tests/phpunit/PromptRulesTest.php`.
  - Add a method named `test_rerank_payload_replaces_existing_score_with_contextual_blended_score`.
  - Build a block recommendation payload with a suggestion that has `ranking.score` set to a deliberately high stale value such as `0.9`.
  - Pass ranking context that forces a concrete contextual calculation, for example a style update to `color.text` with supported context paths only for `color.background`.
  - Call `Prompt::rerank_payload( $payload, $ranking_context )`.
  - Assert:
    - `ranking.score` is not `0.9`.
    - `ranking.score` equals `ranking.blendedScore`.
    - `ranking.contextScore` is present.
    - `ranking.sourceSignals` contains `contextual_ranking_v1` exactly once.
  - Command: `composer run test:php -- --filter PromptRulesTest`
  - Expected before implementation: the new test fails because `RankingContract::normalize()` preserves the input score.

- [ ] Implement the rerank input cleanup in `inc/LLM/Prompt.php`.
  - Immediately before the `RankingContract::normalize()` call in `Prompt::rerank_payload()`, derive a metadata-only ranking array:

    ```php
    $ranking_metadata = $ranking;
    foreach ( [ 'score', 'confidence', 'modelScore', 'deterministicScore', 'contextScore', 'blendedScore', 'contextEvidence', 'contextPenalties', 'rankingVersion' ] as $contextual_key ) {
    	unset( $ranking_metadata[ $contextual_key ] );
    }
    ```

  - Pass `$ranking_metadata` as the first argument to `RankingContract::normalize()`.
  - Keep the existing defaults built from `$computed_score` and `RankingContract::contextual_component_defaults()`.
  - Keep original descriptive metadata such as `reason`, `risk`, `designPrinciple`, `sourceSignals`, `safetyMode`, and `freshnessMeta` unless the computed defaults intentionally replace them.

- [ ] Add a narrowly scoped support test if the cleanup list is moved into `RankingContract`.
  - If introducing a shared constant such as `RankingContract::CONTEXTUAL_COMPONENT_KEYS`, add or update `tests/phpunit/RankingContractTest.php` to prove it contains every computed key that must not be preserved from stale LLM input.
  - Do not change `RankingContract::normalize()` precedence globally; other callers may intentionally prefer explicit input scores.

- [ ] Verify Task 1.
  - Run `composer run test:php -- --filter 'PromptRulesTest|RankingContractTest'`.
  - Confirm the new rerank test fails before implementation and passes after implementation.

## Task 2: Use Style Context for Support Fit and Style No-Op Detection

- [ ] Add failing style support-path tests in `tests/phpunit/RecommendationContextScorerTest.php`.
  - Add `test_style_surface_support_fit_reads_style_context_supported_paths`.
  - Use `RecommendationContextScorer::score()` with no `executionContract`.
  - Provide this style context shape:

    ```php
    'context' => [
    	'styleContext' => [
    		'supportedStylePaths' => [
    			[
    				'path' => [ 'color', 'background' ],
    			],
    		],
    	],
    ],
    ```

  - Use a supported suggestion with `operations => [ [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => '#123456' ] ]`.
  - Assert `supports_fit` is greater than `0.7` and `unsupported_control` is absent.
  - Use an unsupported suggestion with `path => [ 'color', 'text' ]`.
  - Assert `supports_fit` is `0.45` and `unsupported_control` is `0.20`.
  - Command: `composer run test:php -- --filter RecommendationContextScorerTest`
  - Expected before implementation: the supported case stays at the neutral `0.55` score.

- [ ] Add failing style no-op tests in `tests/phpunit/RecommendationContextScorerTest.php`.
  - Add `test_style_surface_no_op_detection_reads_current_global_styles_config`.
  - Use `context.styleContext.currentConfig.styles.color.background = '#123456'`.
  - Use `operations => [ [ 'type' => 'set_styles', 'path' => [ 'color', 'background' ], 'value' => '#123456' ] ]`.
  - Assert `possible_no_op` is `0.25`.
  - Add a non-matching value case and assert `possible_no_op` is absent.
  - Add a missing-current-value case and assert `possible_no_op` is absent.
  - Add a preset/raw mismatch case, for example current `var:preset|color|accent` and proposed `#123456`, and assert `possible_no_op` is absent. Do not implement preset-to-raw equivalence in this remediation.

- [ ] Update `inc/Support/RecommendationContextScorer.php` support scoring.
  - Change the `score()` call from:

    ```php
    $support = self::score_support_fit( $suggestion, $execution_contract );
    ```

    to:

    ```php
    $support = self::score_support_fit( $suggestion, $execution_contract, $context );
    ```

  - Update the helper signature to `private static function score_support_fit( array $suggestion, array $execution_contract, array $context ): array`.
  - Preserve existing execution-contract precedence:

    ```php
    $paths = self::normalize_path_inventory( $execution_contract['styleSupportPaths'] ?? [] );
    if ( [] === $paths ) {
    	$paths = self::normalize_style_context_path_inventory( $context['styleContext']['supportedStylePaths'] ?? [] );
    }
    ```

  - Add `normalize_style_context_path_inventory()` near `normalize_path_inventory()`.
  - It must accept style context entries shaped as `[ 'path' => [ 'color', 'background' ] ]`, direct arrays like `[ 'color', 'background' ]`, and dot strings like `color.background`.
  - It must normalize through the existing `normalize_path()` method so `style.color.background`, `color.background`, and `[ 'color', 'background' ]` collapse to the same key.

- [ ] Update `inc/Support/RecommendationContextScorer.php` no-op scoring.
  - Keep the existing block `attributeUpdates` behavior.
  - If block `attributeUpdates` are empty, evaluate style operations from both `operations` and `proposedOperations`.
  - Only inspect operation types `set_styles` and `set_block_styles`.
  - For each scalar/null operation value, compare it to `context.styleContext.currentConfig.styles` at the normalized operation path.
  - Return `true` only when at least one style operation has a concrete path and every inspected operation has an existing current value that is strictly equal to the proposed value.
  - Return `false` when the current style value is missing, the proposed value differs, the path is empty, or the operation value is not scalar/null.
  - Do not compare `set_theme_variation` operations in this task.

- [ ] Verify Task 2.
  - Run `composer run test:php -- --filter RecommendationContextScorerTest`.
  - Run `composer run test:php -- --filter 'StylePromptTest|StyleAbilitiesTest'` to confirm the scorer changes do not break the style prompt and style ability contracts.

## Task 3: Exclude All Diagnostic Activity from Inline Template History

- [ ] Add failing selector tests in `src/store/__tests__/activity-history.test.js`.
  - Extend the existing `getLatestAppliedActivity skips request diagnostics` case or add `getLatestAppliedActivity skips recommendation outcomes`.
  - Create an applied template activity with timestamp `2026-03-24T10:00:00Z`.
  - Create a newer recommendation outcome with:

    ```js
    {
    	type: 'recommendation_outcome',
    	surface: 'template',
    	timestamp: '2026-03-24T10:00:01Z',
    }
    ```

  - Assert `getLatestAppliedActivity( [ applied, outcome ] )` returns the applied activity.

- [ ] Add failing inline template UI tests in `src/templates/__tests__/TemplateRecommender.test.js`.
  - Rename the existing request-diagnostic test to cover all diagnostic activity, or add a second test named `keeps recommendation outcomes out of inline template actions`.
  - Add a `recommendation_outcome` activity with `surface: 'template'`, `suggestion: 'Recommendations shown'`, and `target.templateRef = TEMPLATE_REF`.
  - Include a real `apply_template_suggestion` activity for the same template.
  - Render the panel, open the “Recent AI Actions” section, and assert:
    - The real applied activity label is visible.
    - The recommendation outcome label is not visible.
    - The outcome does not create an Undo button or latest-applied notice.
  - Add a second outcome fixture that matches through `document.scopeKey` instead of `target.templateRef`, because `TemplateRecommender` accepts both matching paths.

- [ ] Add the same diagnostic UI guard for template parts in `src/template-parts/__tests__/TemplatePartRecommender.test.js`.
  - Add `keeps recommendation outcomes out of inline template-part actions`.
  - Use `surface: 'template-part'`, `target.templatePartRef = 'theme//header'`, and a normal `apply_template_part_suggestion` entry.
  - Assert only the real applied activity appears after opening “Recent AI Actions”.

- [ ] Centralize diagnostic classification in `src/store/activity-history.js`.
  - Export a new helper:

    ```js
    export function isDiagnosticActivityEntry( entry ) {
    	return (
    		isRequestDiagnosticEntry( entry ) ||
    		isRecommendationOutcomeEntry( entry )
    	);
    }
    ```

  - Replace internal request-only checks in `getResolvedActivityEntries()` and `getLatestAppliedActivity()` with `isDiagnosticActivityEntry()`.
  - Recommendation outcomes should receive diagnostic undo state the same way request diagnostics do, unless an existing test proves a distinct persisted `not_applicable` status must be preserved. If a conflict appears, keep `canUndo: false` and add the smallest assertion that documents the chosen behavior.

- [ ] Replace duplicate component predicates.
  - In `src/templates/TemplateRecommender.js`, import `isDiagnosticActivityEntry` from `../store/activity-history` and remove the local `isRequestDiagnosticActivity()` helper.
  - In `src/template-parts/TemplatePartRecommender.js`, import `isDiagnosticActivityEntry` and remove the local request-only helper.
  - Replace the inline filters with:

    ```js
    .filter( ( entry ) => ! isDiagnosticActivityEntry( entry ) )
    ```

  - In `src/components/AIActivitySection.js`, import `isDiagnosticActivityEntry` and use it in place of the local request-only `isDiagnosticEntry()` helper so any defensive rendering path labels recommendation outcomes as review/not-applicable instead of normal actions.

- [ ] Verify Task 3.
  - Run:

    ```bash
    npm run test:unit -- --runTestsByPath src/store/__tests__/activity-history.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/components/__tests__/AIActivitySection.test.js --runInBand
    ```

  - Confirm the new tests fail before implementation and pass after implementation.

## Task 4: Documentation and Full Verification

- [ ] Review docs for diagnostic activity wording.
  - Check `docs/reference/activity-state-machine.md`, `docs/features/activity-history.md`, and the dirty docs already touched in the working tree.
  - If they already state that recommendation outcomes are diagnostics and not inline actions, leave them unchanged.
  - If they only mention request diagnostics, add one concise sentence that both `request_diagnostic` and `recommendation_outcome` are diagnostic activity and are excluded from inline recent-action lists.

- [ ] Run the PHP verification set:

  ```bash
  composer run test:php -- --filter 'PromptRulesTest|RankingContractTest|RecommendationContextScorerTest|StylePromptTest|StyleAbilitiesTest'
  ```

- [ ] Run the JavaScript verification set:

  ```bash
  npm run test:unit -- --runTestsByPath src/store/__tests__/activity-history.test.js src/store/__tests__/store-actions.test.js src/store/__tests__/recommendation-outcomes.test.js src/templates/__tests__/TemplateRecommender.test.js src/template-parts/__tests__/TemplatePartRecommender.test.js src/components/__tests__/AIActivitySection.test.js --runInBand
  ```

- [ ] Run repository hygiene checks:

  ```bash
  npm run check:docs
  git diff --check
  ```

- [ ] Run the fast aggregate verifier:

  ```bash
  npm run verify -- --skip-e2e
  ```

- [ ] Record any unavailable prerequisite as a blocker with the exact command and error.
  - Do not silently skip `lint:plugin`, `check:docs`, or aggregate verification if they fail due to local prerequisites.

## Acceptance Criteria

- [ ] Block rerank output never preserves stale LLM `ranking.score` when a contextual rerank score was computed.
- [ ] For contextual rerank output, `ranking.score` and `ranking.blendedScore` match the computed blended score.
- [ ] Style-surface suggestions with supported `set_styles` or `set_block_styles` paths receive the support boost from `styleContext.supportedStylePaths`.
- [ ] Style-surface suggestions with unsupported concrete style paths receive `unsupported_control`.
- [ ] Exact style operation no-ops receive `possible_no_op`; missing current values and non-equal values do not.
- [ ] Template and template-part inline “Recent AI Actions” lists exclude both `request_diagnostic` and `recommendation_outcome` entries.
- [ ] `getLatestAppliedActivity()` ignores both diagnostic activity types.
- [ ] Targeted PHP tests, targeted JS tests, `npm run check:docs`, `git diff --check`, and `npm run verify -- --skip-e2e` have either passed or have a documented environmental blocker.
