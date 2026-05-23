# Contextual Ranking V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Status update (2026-05-23):** Pattern-surface contextual ranking is now part of the current contract. The follow-up plan in `docs/superpowers/plans/2026-05-23-contextual-ranking-outcome-remediation.md` added `RecommendationContextScorer` scoring and `contextual_ranking_v1` source-signal emission to `PatternAbilities::recommend_patterns()` so pattern recommendation outcome diagnostics can join contextual evidence the same way the other recommendation surfaces do. Dedicated JS `PatternRecommender` shelf reordering UI remains deferred to a later Pattern Ranking V1 phase.

**Goal:** Feed real WordPress context into recommendation ranking so Flavor Agent can prefer suggestions that match the user prompt, block capabilities, section role, docs freshness, design semantics, accessibility/context fit, and visible scope before recommendations are shown.

**Architecture:** Add one bounded PHP scoring helper that converts existing request context plus each parsed recommendation into a normalized context score and explainable evidence. Keep `RankingContract::blend_score()` as the scoring seam, keep model-facing LLM ranking schemas stable, and enrich recommendation/output diagnostics with plugin-generated component scores that Outcome Signals V1 can join by recommendation set and suggestion key.

**Tech Stack:** WordPress plugin PHP, PHPUnit, `FlavorAgent\Support\RankingContract`, parser families in `inc/LLM/`, existing `DesignSemantics` and docs-grounding summaries, local activity outcome diagnostics, `RecommendationEvaluationTest` parser fixtures.

---

## Scope

This plan is the next bounded recommendation phase after Outcome Signals V1. It is not automatic learning and it is not full design diagnosis.

In scope:

- Replace parser-family `context => null` inputs to `RankingContract::blend_score()` for block, style, template, template-part, navigation, and dedicated pattern recommendations.
- Score only signals already present in request context, parser output, execution contracts, docs-grounding summaries, pattern visibility, native preset metadata, and current design semantics.
- Emit per-recommendation ranking metadata: `modelScore`, `deterministicScore`, `contextScore`, `blendedScore`, `contextEvidence`, `contextPenalties`, and `rankingVersion`.
- Add compact local outcome ranking diagnostics: per-suggestion events store one ranking snapshot; aggregate `shown` events store a bounded `rankingSet` summary for the top recommendations. Neither shape stores raw prompts or recommendation text.
- Add provider-free fixtures that prove context-aware ranking changes order in predictable situations.
- Exercise the block final-rerank path through parse, block-context enforcement, and rerank so fixture proof matches production behavior.

Out of scope:

- Automatic weight tuning.
- Personalized learning from user behavior.
- Remote telemetry.
- Silent prompt mutation.
- Full contrast/spacing/hierarchy/rhythm diagnosis beyond existing `designSemantics` fields.
- New outcome event types such as dismissed or manually_followed.
- Complex adaptive ranking.
- Provider-facing `ResponseSchema` expansion for plugin-generated ranking metadata.
- Dedicated JS `PatternRecommender` shelf reordering UI. `PatternAbilities::recommend_patterns()` does compute contextual ranking metadata for pattern recommendations, but the client shelf still renders the server-provided ordering and does not add an additional JS ranking pass in this phase.

## Live Code Findings

- `RankingContract::blend_score()` already accepts `model`, `deterministic`, and `context` components with default weights `0.30`, `0.45`, and `0.25`.
- The main parser families still pass `context => null` in:
  - `inc/LLM/Prompt.php`
  - `inc/LLM/StylePrompt.php`
  - `inc/LLM/TemplatePrompt.php`
  - `inc/LLM/TemplatePartPrompt.php`
  - `inc/LLM/NavigationPrompt.php`
- `Prompt::parse_response()` currently lacks a context parameter, while the other parser families already receive request context.
- Block recommendations need a final rerank after `Prompt::enforce_block_context_rules()` because rejected structural operations are only known after block operation validation.
- Existing `ResponseSchema` ranking objects are model-facing and intentionally small: `score`, `reason`, `sourceSignals`, `designPrinciple`, and `risk`. Contextual Ranking V1 should not add component-score fields to model output schemas.
- Existing ability output schemas use `Registration::ranking_contract_schema()`, which is the correct public contract to extend for plugin-generated ranking fields. That helper is also used by `flavor-agent/recommend-patterns`, so schema work must either include pattern schema compatibility assertions or deliberately split contextual and base ranking schemas.
- Outcome diagnostics already record `recommendationSetId`, `suggestionKey`, rank, `topSuggestionKeys`, and `sourceRequestSignature`, but not ranking evidence.
- `BlockRecommendationExecutionContract::from_context()` emits block `styleSupportPaths` as dot strings such as `color.background`, while style recommendation context uses array paths such as `[ 'color', 'background' ]`.
- `docs/reference/cross-surface-validation-gates.md` treats multi-surface/shared recommendation changes as Gate 7 changes and requires targeted Playwright evidence, specifically `npm run test:e2e:playground` and `npm run test:e2e:wp70`, or an explicit recorded blocker/waiver.

## Review Finding Remediation Plan

These corrections must be treated as part of Contextual Ranking V1, not as optional follow-up notes.

1. Missing browser release gates.
   - Root issue: Task 10 originally stopped at `node scripts/verify.js --skip-e2e`, which is the non-browser aggregate gate only. Contextual Ranking V1 changes a shared ranking subsystem plus block, style, template, template-part, navigation, and outcome diagnostics, so it triggers the multi-surface release gate.
   - Proof mechanism: Before implementation, inspect `docs/reference/cross-surface-validation-gates.md` and confirm Gate 7 plus Harness Mapping still require browser evidence for shared recommendation changes. During implementation, run `npm run test:e2e:playground` and `npm run test:e2e:wp70`; if either harness is unavailable or known-red, record the exact blocker or waiver in the implementation notes instead of silently skipping it.
   - Remediation instructions: Keep `node scripts/verify.js --skip-e2e` as the non-browser aggregate step, then add explicit Task 10 steps for both Playwright harnesses before diff hygiene. Update Success Criteria so Contextual Ranking V1 cannot be called done without recorded browser outcomes or an explicit waiver.

2. Shared ranking schema and ranking normalization now apply to pattern output.
   - Root issue: `Registration::ranking_contract_schema()` is shared by block, style, template, template-part, navigation, and pattern recommendation output schemas, so extending that helper intentionally changes the public pattern schema. `PatternAbilities::recommend_patterns()` also passes model/ranker-supplied `$rec['ranking']` into `RankingContract::normalize()`. If `RankingContract::normalize()` preserves input-only `modelScore`, `deterministicScore`, `contextScore`, `blendedScore`, `contextEvidence`, `contextPenalties`, or `rankingVersion`, a pattern ranker response can spoof plugin-generated contextual metadata.
   - Proof mechanism: Extend `RegistrationTest` to assert the new optional ranking fields on all contextual surfaces and on the existing `flavor-agent/recommend-patterns` output schema. Add a `RankingContractTest` regression proving plugin-owned contextual component fields are ignored when they appear only in `$input` and preserved only when supplied by `$defaults`. Add `PatternAbilitiesTest` coverage proving pattern recommendations receive plugin-generated contextual defaults and emit `contextual_ranking_v1`, while ranker-supplied contextual fields alone cannot spoof those plugin-owned fields.
   - Remediation instructions: Keep shared public schema compatibility only when the runtime output contract is also guarded. Treat contextual component fields as plugin-owned defaults in `RankingContract::normalize()`; do not preserve those fields from model/provider `$input` alone. Pattern recommendations may emit Contextual Ranking V1 metadata only from plugin-generated scorer defaults.

3. Block support-fit scoring could miss the live execution-contract shape.
   - Root issue: The plan originally described block style support using `styleSupportPaths` but did not require a test for the live dot-string format returned by `BlockRecommendationExecutionContract::from_context()`. A scorer that only compares array paths would pass style-surface tests while treating supported block style updates as unsupported or unknown.
   - Proof mechanism: Add `RecommendationContextScorerTest` coverage where `executionContract.styleSupportPaths` contains dot strings such as `color.background`; assert a block style update to `style.color.background` receives supported evidence and an absent path receives `unsupported_control`.
   - Remediation instructions: Normalize all support paths before comparison. The scorer must canonicalize array paths, dot strings, nested block `attributeUpdates.style` paths, operation `path` arrays, and block execution-contract paths into the same dot-string form before scoring `supports_fit`.

4. Block contextual-ranking fixtures can prove the wrong behavior if structural actions stay disabled.
   - Root issue: `Prompt::enforce_block_context_rules()` validates block structural proposals through `BlockOperationValidator::normalize_context()` and the default-off `flavor_agent_block_structural_actions_enabled()` gate. The plan's block fixture expects `insert_pattern` to survive enforcement, but without enabling the rollout flag both candidate operations are rejected as `block_structural_actions_disabled`, so the fixture cannot prove supported operations outrank rejected operations.
   - Proof mechanism: Add `enableBlockStructuralActions => true` to the structural block fixture and wrap only that fixture's parse/enforce/rerank path with `add_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' )` plus a `finally` cleanup. In `test_contextual_ranking_parser_fixtures_choose_expected_top_suggestions()`, assert that fixtures with `enableBlockStructuralActions` have at least one accepted operation after materialization and that the expected top suggestion has no `block_structural_actions_disabled` rejection.
   - Remediation instructions: Treat structural-action availability as an explicit fixture precondition, not a global test-suite assumption. Keep the default-off production setting unchanged. For the contextual evaluation helper, enable the filter only around the fixture materialization that declares `enableBlockStructuralActions`, remove the filter in `finally`, then run the same parse, enforcement, and final rerank path production uses. Add the assertion before accepting the fixture as proof.

5. Existing `ResponseSchemaTest` does not prove plugin-generated component fields stayed out of model-facing schemas.
   - Root issue: The current response-schema tests assert the strict LLM `ranking` object is nullable and that its required keys are still `score`, `reason`, `sourceSignals`, `designPrinciple`, and `risk`. Those assertions would still pass if optional `modelScore`, `deterministicScore`, `contextScore`, `blendedScore`, `contextEvidence`, `contextPenalties`, or `rankingVersion` were accidentally added to `ResponseSchema`, because the tests do not assert those keys are absent.
   - Proof mechanism: Extend `ResponseSchemaTest` with explicit negative assertions on every strict LLM ranking object for block, style, template, template-part, and navigation. The test must fail if any plugin-generated contextual component field appears in the provider-facing schema, while still confirming nullable ranking support and the existing required-key set.
   - Remediation instructions: Do not add contextual component fields to `inc/LLM/ResponseSchema.php`. Keep those fields on plugin-generated ability output schemas only through `Registration::ranking_contract_schema()`. Add a helper such as `assert_no_contextual_component_fields_in_llm_ranking_schema()` in `ResponseSchemaTest` and call it for every ranking schema node currently covered by the nullable-ranking tests.

6. Contextual ordering fixtures can pass or fail for the wrong reason under existing blend weights.
   - Root issue: `RankingContract::blend_score()` keeps `model: 0.30`, `deterministic: 0.45`, and `context: 0.25`, while several fixtures intentionally give the context-fit suggestion lower model confidence. A fixture that asserts only the top label may prove deterministic scoring, or fail after a harmless deterministic-score tweak without explaining which component moved.
   - Proof mechanism: In `RecommendationEvaluationTest`, record `modelScore`, `deterministicScore`, `contextScore`, and `blendedScore` for the top and runner-up suggestions. Assert the winner has `blendedScore >= runnerUp.blendedScore + 0.01` and a higher `contextScore` than the runner-up.
   - Remediation instructions: Add component snapshot assertions to every contextual fixture that materializes at least two suggestions. Include the component snapshot in the assertion message so an implementation failure shows whether model, deterministic, or context scoring caused the result.

7. Penalty thresholds and support-fit precedence are under-specified.
   - Root issue: The plan defines penalty keys and an aggregate cap, but the exact triggers and values for `weak_prompt_match` and `validation_risk` were not fixed. Support fit also used broad `allowedPanels` and concrete `styleSupportPaths` as peers, which could make an explicitly unsupported path look supported because its broad panel is allowed.
   - Proof mechanism: `RecommendationContextScorerTest` must assert exact penalty values, penalty cap behavior, and block support-fit precedence where `styleSupportPaths` omits `color.background` while `allowedPanels` includes `color`.
   - Remediation instructions: Add a fixed V1 penalty table, cap aggregate penalties at `0.35`, and define support precedence so explicit path inventory wins over broad panel fallback.

8. Outcome ranking diagnostics can leak generated text if `suggestionKey` falls back through label-derived identity.
   - Root issue: Outcome diagnostics must not store raw prompts or generated recommendation text, but a `rankingSet` implementation that calls `getSuggestionOutcomeKey()` can derive fallback identity from labels or other generated suggestion fields.
   - Proof mechanism: Add PHP and JS regressions with a suggestion label such as `"Use secret launch copy"` and assert neither `ranking`, `rankingSet`, nor `suggestionKey` contains `secret`, `launch`, or `copy`.
   - Remediation instructions: For aggregate ranking diagnostics, `rankingSet.suggestionKey` must be an existing stable key, an existing `recommendationOutcome.suggestionKey`, or a deterministic set-local fallback such as `suggestion:1`. It must not derive from raw label, description, operation detail, block content, or pattern payload.

9. Ranking evidence and penalty maps should not use generic structured sanitization.
   - Root issue: `contextEvidence` and `contextPenalties` are plugin-owned compact numeric maps. Passing them through `sanitize_structured_value()` could preserve nested arrays, strings, or raw payload fragments that do not belong in ranking diagnostics.
   - Proof mechanism: Add `RankingContractTest` coverage proving raw strings, nested payloads, unknown keys, and out-of-range values are dropped or clamped when plugin defaults provide contextual maps.
   - Remediation instructions: Add `RankingContract::normalize_numeric_ranking_map()` for contextual ranking maps. It must sanitize keys, restrict to known evidence/penalty keys, clamp values to `0.0..1.0`, cap at 12 entries, and drop non-numeric values.

10. Accessibility and design-semantics scoring can overboost generic suggestions.
    - Root issue: A rule that boosts accessibility when context or suggestion mentions contrast can reward generic copy solely because current design semantics mention contrast.
    - Proof mechanism: Add scorer tests where context has `mainDesignIssue: contrast`; a generic suggestion remains neutral for `accessibility_fit`, while a suggestion that explicitly addresses contrast/readability receives the boost.
    - Remediation instructions: Boost accessibility only when the suggestion addresses accessibility/contrast/readability/focus/keyboard concerns or when both context and suggestion share relevant accessibility tokens. Enumerate the negative/contradictory signals that can demote accessibility and design-semantics fit.

11. Rerank, no-op, and duplicate-source-signal edge cases need explicit regressions.
    - Root issue: `Prompt::rerank_payload()` assumes parser-generated `modelScore` and `deterministicScore` exist; no-op tests do not yet cover partial updates or exact style no-ops; and repeated parse/rerank paths can append `contextual_ranking_v1` twice if source signals are not deduped.
    - Proof mechanism: Add block rerank tests for legacy payloads with only `ranking.score`, scorer tests for partial no-ops and style no-op boundaries, and parser/rerank tests that assert `contextual_ranking_v1` appears at most once.
    - Remediation instructions: Define rerank fallbacks, add conservative no-op tests, centralize plugin-owned ranking component keys in `RankingContract`, and dedupe `sourceSignals` through `RankingContract::normalize()` or `array_unique()`.

## Scoring Contract

Create `FlavorAgent\Support\RecommendationContextScorer` with this public API:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Support;

final class RecommendationContextScorer {
	public const VERSION = 'contextual-ranking-v1';

	/**
	 * @param array{
	 *   surface?: string,
	 *   group?: string,
	 *   suggestion?: array<string, mixed>,
	 *   context?: array<string, mixed>,
	 *   prompt?: string,
	 *   docsGrounding?: array<string, mixed>,
	 *   executionContract?: array<string, mixed>
	 * } $input
	 * @return array{score: float, evidence: array<string, float>, penalties: array<string, float>}
	 */
	public static function score( array $input ): array;
}
```

Use these bounded evidence keys. Each value must be a float from `0.0` to `1.0`:

```php
[
	'prompt_match'          => 0.55,
	'operation_fit'         => 0.55,
	'supports_fit'          => 0.55,
	'section_role_match'    => 0.55,
	'docs_freshness'        => 0.55,
	'pattern_readiness'     => 0.55,
	'visible_scope_match'   => 0.55,
	'native_preset_fit'     => 0.55,
	'accessibility_fit'     => 0.55,
	'design_semantics_fit'  => 0.55,
]
```

Use these bounded penalties. Each value must be a float from `0.0` to `1.0`, and the aggregate penalty applied to the weighted evidence score must be capped at `0.35`:

```php
[
	'possible_no_op'       => 0.0,
	'weak_prompt_match'   => 0.0,
	'unsupported_control' => 0.0,
	'stale_docs'          => 0.0,
	'validation_risk'     => 0.0,
]
```

Use these fixed V1 penalty triggers and values:

| Penalty | Trigger | V1 value |
| --- | --- | ---: |
| `weak_prompt_match` | Non-empty prompt and computed `prompt_match < 0.35`. | `0.12` |
| `possible_no_op` | All proposed shallow scalar changes exactly match current state. | `0.25` |
| `unsupported_control` | Explicit support inventory exists and requested path, panel, pattern, area, or navigation target is absent. | `0.20` |
| `stale_docs` | Docs status is `stale` or docs freshness includes `stale`. | `0.15` |
| `validation_risk` | Rejected operations exist, validation/rejection hints are present, or rejected contrast/accessibility hints are present. | `0.15` |

Use fixed V1 weights:

```php
[
	'prompt_match'         => 0.18,
	'operation_fit'        => 0.18,
	'supports_fit'         => 0.16,
	'section_role_match'   => 0.12,
	'docs_freshness'       => 0.12,
	'pattern_readiness'    => 0.08,
	'visible_scope_match'  => 0.06,
	'native_preset_fit'    => 0.06,
	'accessibility_fit'    => 0.02,
	'design_semantics_fit' => 0.02,
]
```

The final context score is:

```php
$score = max(
	0.0,
	min(
		1.0,
		round( $weighted_evidence_score - min( 0.35, $penalty_sum ), 4 )
	)
);
```

Neutral unknowns are `0.55`, not `0.0`, so a missing optional context signal does not punish otherwise valid recommendations.

Support-fit precedence is fixed for V1:

1. If explicit `styleSupportPaths` inventory exists and a suggestion has a concrete style path, concrete path comparison wins.
2. `executionContract.allowedPanels` is only a fallback when no concrete style path can be extracted.
3. If both explicit paths and broad panels exist, an absent concrete path receives `supports_fit: 0.45` plus `unsupported_control: 0.20`, even if the broad panel appears allowed.
4. Missing support inventory returns neutral `supports_fit: 0.55` and no penalty.

## Files

Create:

- `inc/Support/RecommendationContextScorer.php` - normalized, bounded context scoring and evidence.
- `tests/phpunit/RecommendationContextScorerTest.php` - unit coverage for scoring helper behavior.
- `tests/phpunit/fixtures/recommendation-evaluation-contextual-ranking-fixtures.php` - provider-free parser fixtures for Contextual Ranking V1 ordering.

Modify:

- `inc/Support/RankingContract.php` - preserve ranking component metadata and context evidence.
- `inc/LLM/Prompt.php` - accept context/ranking metadata, replace `context => null`, and expose a final block rerank helper after context enforcement.
- `inc/LLM/StylePrompt.php` - pass scored context into `blend_score()` and emit ranking metadata.
- `inc/LLM/TemplatePrompt.php` - pass scored context into `blend_score()` and emit ranking metadata.
- `inc/LLM/TemplatePartPrompt.php` - pass scored context into `blend_score()` and emit ranking metadata.
- `inc/LLM/NavigationPrompt.php` - pass scored context into `blend_score()` and emit ranking metadata.
- `inc/Abilities/BlockAbilities.php` - pass prompt/docs/execution context to block parse and final rerank.
- `inc/Abilities/StyleAbilities.php` - pass prompt/docs summary to style parser.
- `inc/Abilities/TemplateAbilities.php` - pass prompt/docs summary to template and template-part parsers.
- `inc/Abilities/NavigationAbilities.php` - pass prompt/docs summary to navigation parser.
- `inc/Abilities/Registration.php` - expose plugin-generated ranking fields in public ability output schemas.
- `inc/Activity/RecommendationOutcome.php` - normalize compact local ranking snapshots and aggregate ranking sets in outcome diagnostics.
- `src/store/recommendation-outcomes.js` - include compact ranking snapshots and aggregate ranking sets when decorating/building outcome entries.
- `src/store/__tests__/recommendation-outcomes.test.js` - cover JS ranking snapshot, ranking set, and privacy normalization.
- `tests/phpunit/RankingContractTest.php` - cover ranking component metadata.
- `tests/phpunit/RegistrationTest.php` - cover output schema fields, including the intentional shared-schema impact on pattern recommendation output.
- `tests/phpunit/RecommendationOutcomeTest.php` - cover local outcome ranking snapshots.
- `tests/phpunit/RecommendationEvaluationTest.php` - load parser-backed contextual ranking fixtures and assert top-ranked movement.
- `tests/phpunit/BlockAbilitiesTest.php` - cover block ability ranking-context propagation and post-enforcement rerank behavior.
- `tests/phpunit/StyleAbilitiesTest.php` - cover style ability ranking-context propagation.
- `tests/phpunit/TemplateAbilitiesTest.php` - cover template and template-part ability ranking-context propagation.
- Parser tests closest to each surface:
  - `tests/phpunit/PromptRulesTest.php`
  - `tests/phpunit/StylePromptTest.php`
  - `tests/phpunit/TemplatePromptTest.php`
  - `tests/phpunit/TemplatePartPromptTest.php`
  - `tests/phpunit/NavigationAbilitiesTest.php`
- `docs/reference/activity-state-machine.md` - document that local outcome diagnostics may include bounded ranking component scores, numeric context evidence, and aggregate `shown` ranking sets, with no raw prompt or generated text.

Do not edit generated `build/` or `dist/` artifacts.

## Implementation Slices

Use these slices if this plan is implemented across commits or PRs:

1. Scorer foundation: Task 0, `RecommendationContextScorer`, and `RecommendationContextScorerTest`.
2. Ranking contract and schemas: `RankingContract` metadata preservation, `Registration` schema extension with pattern compatibility proof, and `ResponseSchema` non-drift verification.
3. Parser wiring: style, template, template-part, navigation, block parser context, and post-enforcement block rerank.
4. Diagnostics and evaluation: outcome `ranking`/`rankingSet` diagnostics, contextual ranking fixtures that run the production-equivalent block rerank path, activity docs, and aggregate verification.

## Task 0: Inventory Context Field Availability

**Files:**

- No production file changes.
- Modify: `tests/phpunit/RecommendationContextScorerTest.php` in Task 1 for fallback coverage.

- [ ] **Step 1: Confirm context field paths by surface**

Before writing scorer implementation code, inspect the current parser and ability contexts and use this fallback map:

| Evidence source | Block | Style | Template | Template-part | Navigation | Fallback |
| --- | --- | --- | --- | --- | --- | --- |
| prompt text | `$ranking_context['prompt']` from `BlockAbilities::recommend_block()` | `$ranking_context['prompt']` from `StyleAbilities::recommend_style()` | `$ranking_context['prompt']` from `TemplateAbilities::recommend_template()` | `$ranking_context['prompt']` from `TemplateAbilities::recommend_template_part()` | `$ranking_context['prompt']` from `NavigationAbilities::recommend_navigation()` | Empty prompt returns neutral `prompt_match: 0.55`. |
| docs grounding summary | `$ranking_context['docsGrounding']` | `$ranking_context['docsGrounding']` | `$ranking_context['docsGrounding']` | `$ranking_context['docsGrounding']` | `$ranking_context['docsGrounding']` | Missing summary returns neutral `docs_freshness: 0.55`. |
| selected block name | `$context['block']['name']` | `$context['scope']['blockName']` or `$context['styleContext']['styleBookTarget']['blockName']` | N/A | operation targets / block tree names | N/A | Missing block identity stays neutral. |
| current attributes | `$context['block']['currentAttributes']` | N/A | N/A | target block attributes when present | navigation attributes only for allowed targets | Missing current values never creates `possible_no_op`. |
| supported style paths | `$ranking_context['executionContract']['styleSupportPaths']` as dot strings such as `color.background`, plus block panels | `$context['styleContext']['supportedStylePaths']` as array path entries | N/A | N/A | N/A | Missing inventory returns neutral `supports_fit: 0.55`, not unsupported. |
| theme tokens | `$context['themeTokens']` | `$context['styleContext']['themeTokens']` | N/A | N/A | `$context['themeTokens']` | Missing tokens returns neutral preset evidence unless an explicit unsupported inventory exists. |
| designSemantics | `$context['designSemantics']` | `$context['styleContext']['designSemantics']` | `$context['designSemantics']` | `$context['designSemantics']` | `$context['designSemantics']` if present | Missing semantics returns neutral `section_role_match` and `design_semantics_fit`. |
| execution contract | `$ranking_context['executionContract']` | N/A | N/A | N/A | N/A | Missing contract never penalizes a block suggestion. |
| visible pattern names | `$context['blockOperationContext']['allowedPatterns'][*]['name']` | N/A | `$context['visiblePatternNames']` | `$context['visiblePatternNames']` | N/A | Missing visible inventory returns neutral `visible_scope_match`. |
| allowed pattern actions | `$context['blockOperationContext']['allowedPatterns']` | N/A | derived from validated template operations / known patterns | derived from validated template-part operations / known patterns | N/A | Missing allowed action inventory returns neutral support evidence. |
| template type | structural identity / selected template metadata when present | `$context['scope']['templateType']` | `$context['templateType']` | N/A | N/A | Missing template type stays neutral. |
| template-part area | structural identity template area when present | N/A | allowed area / template part summaries | `$context['area']`, `$context['slug']`, `$context['designSemantics']['templatePart']` | overlay template part context when present | Missing area stays neutral. |
| navigation target/scope | N/A | N/A | N/A | N/A | `$context['location']`, `$context['overlayContext']`, `$context['targetInventory']`, `$context['menuItems']` | Missing target inventory means structural target support is unknown, not unsupported. |

- [ ] **Step 2: Define V1 fallback rules**

The scorer must use these fallback rules:

- Missing optional context returns neutral evidence (`0.55`) and no penalty.
- Unsupported penalties are allowed only when an explicit support inventory exists and the suggestion points outside it.
- Pattern mismatch penalties are allowed only when pattern suggestions exist and known/visible pattern inventory exists.
- No-op detection is conservative: mark `possible_no_op` only when proposed values exactly match current values after shallow scalar normalization. Do not treat preset-to-raw-color equivalence, missing defaults, or unordered complex arrays as no-ops in V1.
- Unknown surfaces must still return a bounded score, bounded evidence, and bounded penalties.

- [ ] **Step 3: Add fallback coverage to Task 1 tests**

Task 1 must include at least these extra scorer tests:

- Missing context returns neutral evidence and no penalties.
- Unknown surface still returns a bounded score.
- Huge prompt/description inputs are tokenized within fixed caps and no raw token text is returned.
- Unsupported paths are penalized only when support inventory exists.
- Missing support inventory returns neutral `supports_fit`.
- Block support paths compare live execution-contract dot strings such as `color.background` against block style update paths and do not require array-shaped support paths.
- Block style support uses explicit path inventory before broad `allowedPanels`, so `style.color.background` is unsupported when `styleSupportPaths` omits `color.background` even if `allowedPanels` contains `color`.
- Missing visible-scope inventory returns neutral `visible_scope_match: 0.55`, not a positive boost.
- Generic suggestions are not boosted for accessibility solely because the context mentions contrast.
- Partial no-ops, where at least one proposed shallow scalar value differs from current state, do not create `possible_no_op`.
- Style no-op detection is exact: exact current style value matches are penalized, missing current values are not penalized, and preset-to-raw-color equivalence is not treated as a no-op in V1.
- Aggregate penalty capping is proven by a case whose individual penalties exceed `0.35`.

## Task 1: Add Context Scorer Tests

**Files:**

- Create: `tests/phpunit/RecommendationContextScorerTest.php`

- [ ] **Step 1: Write failing scorer tests**

Create `tests/phpunit/RecommendationContextScorerTest.php`:

```php
<?php

declare(strict_types=1);

namespace FlavorAgent\Tests;

use FlavorAgent\Support\RecommendationContextScorer;
use PHPUnit\Framework\TestCase;

final class RecommendationContextScorerTest extends TestCase {

	public function test_prompt_matching_recommendation_scores_above_generic_copy(): void {
		$matching = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'prompt'     => 'Make the hero use the accent background',
				'suggestion' => [
					'label'       => 'Use accent hero background',
					'description' => 'Apply the accent background preset to the hero section.',
					'operations'  => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'background' ],
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'accent',
						],
					],
				],
				'context'    => self::style_context(),
			]
		);

		$generic = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'prompt'     => 'Make the hero use the accent background',
				'suggestion' => [
					'label'       => 'Adjust visual tone',
					'description' => 'Make a small style adjustment.',
					'operations'  => [],
				],
				'context'    => self::style_context(),
			]
		);

		$this->assertGreaterThan( $generic['score'], $matching['score'] );
		$this->assertGreaterThan( 0.7, $matching['evidence']['prompt_match'] );
		$this->assertGreaterThan( 0.0, $generic['penalties']['weak_prompt_match'] );
	}

	public function test_preset_supported_operation_scores_above_freeform_value_when_preset_exists(): void {
		$preset = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'      => 'Use accent background preset',
					'operations' => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'background' ],
							'value'      => 'var:preset|color|accent',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'accent',
						],
					],
				],
				'context'    => self::style_context(),
			]
		);

		$freeform = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'      => 'Use custom background',
					'operations' => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'color', 'background' ],
							'value'     => '#123456',
							'valueType' => 'freeform',
						],
					],
				],
				'context'    => self::style_context(),
			]
		);

		$this->assertGreaterThan( $freeform['score'], $preset['score'] );
		$this->assertSame( 1.0, $preset['evidence']['native_preset_fit'] );
		$this->assertLessThan( 1.0, $freeform['evidence']['native_preset_fit'] );
	}

	public function test_rejected_operations_and_no_ops_are_penalized(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'    => 'block',
				'group'      => 'block',
				'suggestion' => [
					'label'              => 'Keep existing level',
					'attributeUpdates'   => [ 'level' => 2 ],
					'operations'         => [],
					'rejectedOperations' => [
						[
							'code' => 'stale_target',
						],
					],
				],
				'context'    => [
					'block' => [
						'name'              => 'core/heading',
						'currentAttributes' => [ 'level' => 2 ],
					],
				],
			]
		);

		$this->assertGreaterThan( 0.0, $result['penalties']['possible_no_op'] );
		$this->assertGreaterThan( 0.0, $result['penalties']['validation_risk'] );
		$this->assertLessThan( 0.55, $result['score'] );
	}

	public function test_docs_freshness_rewards_grounded_current_guidance_and_demotes_stale(): void {
		$current = RecommendationContextScorer::score(
			[
				'surface'       => 'navigation',
				'suggestion'    => [
					'label'       => 'Simplify overlay menu',
					'description' => 'Use the current overlay menu behavior.',
					'changes'     => [
						[
							'type'   => 'overlay',
							'target' => 'navigation',
							'detail' => 'Switch overlay mode.',
						],
					],
				],
				'docsGrounding' => [
					'status'    => 'grounded',
					'freshness' => [ 'current' ],
					'coverage'  => [ 'hasCurrentReleaseCycle' => true ],
				],
			]
		);

		$stale = RecommendationContextScorer::score(
			[
				'surface'       => 'navigation',
				'suggestion'    => [
					'label'       => 'Simplify overlay menu',
					'description' => 'Use the current overlay menu behavior.',
					'changes'     => [
						[
							'type'   => 'overlay',
							'target' => 'navigation',
							'detail' => 'Switch overlay mode.',
						],
					],
				],
				'docsGrounding' => [
					'status'    => 'stale',
					'freshness' => [ 'stale' ],
				],
			]
		);

		$this->assertSame( 1.0, $current['evidence']['docs_freshness'] );
		$this->assertGreaterThan( $stale['score'], $current['score'] );
		$this->assertGreaterThan( 0.0, $stale['penalties']['stale_docs'] );
	}

	public function test_missing_optional_context_returns_neutral_evidence_without_penalties(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'       => 'General polish',
					'description' => 'Make a small improvement.',
				],
			]
		);

		$this->assertSame( 0.55, $result['score'] );

		foreach ( $result['evidence'] as $value ) {
			$this->assertSame( 0.55, $value );
		}

		foreach ( $result['penalties'] as $value ) {
			$this->assertSame( 0.0, $value );
		}
	}

	public function test_unknown_surface_still_returns_bounded_score(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'    => 'unknown-surface',
				'prompt'     => str_repeat( 'accent hero ', 150 ),
				'suggestion' => [
					'label'       => str_repeat( 'accent ', 150 ),
					'description' => str_repeat( 'hero background ', 150 ),
				],
			]
		);

		$this->assertGreaterThanOrEqual( 0.0, $result['score'] );
		$this->assertLessThanOrEqual( 1.0, $result['score'] );
		$this->assertArrayHasKey( 'prompt_match', $result['evidence'] );
		$this->assertArrayNotHasKey( 'accent', $result['evidence'] );
	}

	public function test_huge_inputs_are_capped_and_do_not_return_raw_tokens(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'prompt'     => str_repeat( 'accent hero layout ', 250 ),
				'suggestion' => [
					'label'       => str_repeat( 'accent ', 250 ),
					'description' => str_repeat( 'hero background ', 250 ),
					'category'    => str_repeat( 'layout ', 250 ),
				],
				'context'    => [
					'styleContext' => [
						'designSemantics' => [
							'sectionRole'     => str_repeat( 'hero ', 250 ),
							'mainDesignIssue' => str_repeat( 'layout ', 250 ),
						],
					],
				],
			]
		);

		$this->assertGreaterThanOrEqual( 0.0, $result['score'] );
		$this->assertLessThanOrEqual( 1.0, $result['score'] );
		$this->assertGreaterThanOrEqual( 0.0, $result['evidence']['prompt_match'] );
		$this->assertLessThanOrEqual( 1.0, $result['evidence']['prompt_match'] );
		$this->assertArrayNotHasKey( 'accent', $result['evidence'] );
		$this->assertArrayNotHasKey( 'hero', $result['evidence'] );
		$this->assertArrayNotHasKey( 'layout', $result['penalties'] );
	}

	public function test_support_fit_is_neutral_without_inventory_and_penalized_only_when_known_absent(): void {
		$unknown = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'      => 'Set spacing',
					'operations' => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'spacing', 'margin' ],
							'value'     => 'var:preset|spacing|40',
							'valueType' => 'preset',
						],
					],
				],
			]
		);

		$known_absent = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'      => 'Set spacing',
					'operations' => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'spacing', 'margin' ],
							'value'     => 'var:preset|spacing|40',
							'valueType' => 'preset',
						],
					],
				],
				'context'    => [
					'styleContext' => [
						'supportedStylePaths' => [
							[
								'path'        => [ 'color', 'background' ],
								'valueSource' => 'color',
							],
						],
					],
				],
			]
		);

		$this->assertSame( 0.55, $unknown['evidence']['supports_fit'] );
		$this->assertSame( 0.0, $unknown['penalties']['unsupported_control'] );
		$this->assertLessThan( $unknown['evidence']['supports_fit'], $known_absent['evidence']['supports_fit'] );
		$this->assertGreaterThan( 0.0, $known_absent['penalties']['unsupported_control'] );
	}

	public function test_block_support_fit_accepts_execution_contract_dot_style_paths(): void {
		$supported = RecommendationContextScorer::score(
			[
				'surface'           => 'block',
				'group'             => 'styles',
				'suggestion'        => [
					'label'            => 'Use accent background',
					'attributeUpdates' => [
						'style' => [
							'color' => [
								'background' => 'var:preset|color|accent',
							],
						],
					],
				],
				'executionContract' => [
					'panelMappingKnown' => true,
					'allowedPanels'     => [ 'color' ],
					'styleSupportPaths' => [ 'color.background' ],
				],
			]
		);

		$unsupported = RecommendationContextScorer::score(
			[
				'surface'           => 'block',
				'group'             => 'styles',
				'suggestion'        => [
					'label'            => 'Use accent background',
					'attributeUpdates' => [
						'style' => [
							'color' => [
								'background' => 'var:preset|color|accent',
							],
						],
					],
				],
				'executionContract' => [
					'panelMappingKnown' => true,
					'allowedPanels'     => [ 'typography' ],
					'styleSupportPaths' => [ 'typography.fontSize' ],
				],
			]
		);

		$this->assertSame( 1.0, $supported['evidence']['supports_fit'] );
		$this->assertSame( 0.0, $supported['penalties']['unsupported_control'] );
		$this->assertSame( 0.45, $unsupported['evidence']['supports_fit'] );
		$this->assertGreaterThan( 0.0, $unsupported['penalties']['unsupported_control'] );
	}

	public function test_block_support_fit_prefers_explicit_paths_over_allowed_panels(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'           => 'block',
				'group'             => 'styles',
				'suggestion'        => [
					'label'            => 'Use accent background',
					'attributeUpdates' => [
						'style' => [
							'color' => [
								'background' => 'var:preset|color|accent',
							],
						],
					],
				],
				'executionContract' => [
					'panelMappingKnown' => true,
					'allowedPanels'     => [ 'color' ],
					'styleSupportPaths' => [ 'typography.fontSize' ],
				],
			]
		);

		$this->assertSame( 0.45, $result['evidence']['supports_fit'] );
		$this->assertSame( 0.2, $result['penalties']['unsupported_control'] );
	}

	public function test_visible_scope_is_neutral_without_inventory_and_demotes_known_absent_patterns(): void {
		$unknown = RecommendationContextScorer::score(
			[
				'surface'    => 'template',
				'suggestion' => [
					'label'              => 'Use hero pattern',
					'description'        => 'Add the hero pattern.',
					'patternSuggestions' => [ 'theme/hero' ],
				],
			]
		);

		$known_absent = RecommendationContextScorer::score(
			[
				'surface'    => 'template',
				'suggestion' => [
					'label'              => 'Use hero pattern',
					'description'        => 'Add the hero pattern.',
					'patternSuggestions' => [ 'theme/hero' ],
				],
				'context'    => [
					'visiblePatternNames' => [ 'theme/archive-grid' ],
					'patterns'            => [
						[
							'name'  => 'theme/archive-grid',
							'title' => 'Archive Grid',
						],
					],
				],
			]
		);

		$this->assertSame( 0.55, $unknown['evidence']['visible_scope_match'] );
		$this->assertSame( 0.55, $unknown['evidence']['pattern_readiness'] );
		$this->assertLessThan( $unknown['evidence']['visible_scope_match'], $known_absent['evidence']['visible_scope_match'] );
		$this->assertLessThan( $unknown['evidence']['pattern_readiness'], $known_absent['evidence']['pattern_readiness'] );
	}

	public function test_partial_attribute_changes_are_not_marked_as_no_ops(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'    => 'block',
				'group'      => 'block',
				'suggestion' => [
					'label'            => 'Widen heading alignment',
					'attributeUpdates' => [
						'level' => 2,
						'align' => 'wide',
					],
				],
				'context'    => [
					'block' => [
						'name'              => 'core/heading',
						'currentAttributes' => [
							'level' => 2,
							'align' => 'center',
						],
					],
				],
			]
		);

		$this->assertSame( 0.0, $result['penalties']['possible_no_op'] );
	}

	public function test_style_no_op_detection_is_exact_and_conservative(): void {
		$exact = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'      => 'Keep current background',
					'operations' => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'color', 'background' ],
							'value'     => '#123456',
							'valueType' => 'freeform',
						],
					],
				],
				'context'    => [
					'styleContext' => [
						'currentConfig' => [
							'styles' => [
								'color' => [
									'background' => '#123456',
								],
							],
						],
					],
				],
			]
		);

		$missing_current = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'      => 'Set background',
					'operations' => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'color', 'background' ],
							'value'     => '#123456',
							'valueType' => 'freeform',
						],
					],
				],
				'context'    => [
					'styleContext' => [
						'currentConfig' => [
							'styles' => [],
						],
					],
				],
			]
		);

		$preset_to_raw = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'      => 'Use accent background',
					'operations' => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'color', 'background' ],
							'value'     => 'var:preset|color|accent',
							'valueType' => 'preset',
						],
					],
				],
				'context'    => [
					'styleContext' => [
						'currentConfig' => [
							'styles' => [
								'color' => [
									'background' => '#f5f5f5',
								],
							],
						],
					],
				],
			]
		);

		$this->assertGreaterThan( 0.0, $exact['penalties']['possible_no_op'] );
		$this->assertSame( 0.0, $missing_current['penalties']['possible_no_op'] );
		$this->assertSame( 0.0, $preset_to_raw['penalties']['possible_no_op'] );
	}

	public function test_accessibility_fit_requires_suggestion_match_not_context_only(): void {
		$generic = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'       => 'General polish',
					'description' => 'Make the section feel more refined.',
				],
				'context'    => self::style_context(),
			]
		);

		$contrast = RecommendationContextScorer::score(
			[
				'surface'    => 'style',
				'suggestion' => [
					'label'       => 'Improve color contrast',
					'description' => 'Increase readable contrast in the hero section.',
				],
				'context'    => self::style_context(),
			]
		);

		$this->assertSame( 0.55, $generic['evidence']['accessibility_fit'] );
		$this->assertGreaterThan( $generic['evidence']['accessibility_fit'], $contrast['evidence']['accessibility_fit'] );
	}

	public function test_penalty_sum_is_capped_before_final_score(): void {
		$result = RecommendationContextScorer::score(
			[
				'surface'       => 'style',
				'prompt'        => 'Make the hero use the accent background',
				'suggestion'    => [
					'label'              => 'Keep current unsupported spacing',
					'description'        => 'Generic adjustment.',
					'operations'         => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'spacing', 'margin' ],
							'value'     => '10px',
							'valueType' => 'freeform',
						],
					],
					'rejectedOperations' => [
						[ 'code' => 'unsupported_control' ],
					],
				],
				'context'       => [
					'styleContext' => [
						'supportedStylePaths' => [
							[ 'path' => [ 'color', 'background' ] ],
						],
						'currentConfig'       => [
							'styles' => [
								'spacing' => [
									'margin' => '10px',
								],
							],
						],
					],
				],
				'docsGrounding' => [
					'status'    => 'stale',
					'freshness' => [ 'stale' ],
				],
			]
		);

		$weighted_score = self::weighted_evidence_score( $result['evidence'] );
		$penalty_sum    = array_sum( $result['penalties'] );
		$uncapped_score = max( 0.0, min( 1.0, round( $weighted_score - $penalty_sum, 4 ) ) );
		$capped_score   = max( 0.0, min( 1.0, round( $weighted_score - min( 0.35, $penalty_sum ), 4 ) ) );

		$this->assertGreaterThan( 0.35, $penalty_sum );
		$this->assertSame( $capped_score, $result['score'] );
		$this->assertGreaterThan( $uncapped_score, $result['score'] );
	}

	/**
	 * @param array<string, float> $evidence
	 */
	private static function weighted_evidence_score( array $evidence ): float {
		$weights = [
			'prompt_match'         => 0.18,
			'operation_fit'        => 0.18,
			'supports_fit'         => 0.16,
			'section_role_match'   => 0.12,
			'docs_freshness'       => 0.12,
			'pattern_readiness'    => 0.08,
			'visible_scope_match'  => 0.06,
			'native_preset_fit'    => 0.06,
			'accessibility_fit'    => 0.02,
			'design_semantics_fit' => 0.02,
		];

		$score = 0.0;
		foreach ( $weights as $key => $weight ) {
			$score += (float) ( $evidence[ $key ] ?? 0.55 ) * $weight;
		}

		return round( $score, 4 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function style_context(): array {
		return [
			'styleContext' => [
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
				],
				'themeTokens'         => [
					'colors'       => [ 'accent: #f5f5f5' ],
					'colorPresets' => [
						[
							'slug'  => 'accent',
							'color' => '#f5f5f5',
						],
					],
				],
				'designSemantics'     => [
					'surface'         => 'global-styles',
					'sectionRole'     => 'hero',
					'mainDesignIssue' => 'contrast',
				],
			],
		];
	}
}
```

- [ ] **Step 2: Run the failing scorer test**

Run:

```bash
composer run test:php -- --filter RecommendationContextScorerTest
```

Expected: FAIL because `FlavorAgent\Support\RecommendationContextScorer` does not exist.

## Task 2: Implement Context Scorer

**Files:**

- Create: `inc/Support/RecommendationContextScorer.php`

- [ ] **Step 1: Confirm autoload/bootstrap path**

Confirm `FlavorAgent\Support\RecommendationContextScorer` is autoloaded under the existing PSR-4 support namespace. In this repo, `composer.json` maps `FlavorAgent\` to `inc/`, so `inc/Support/RecommendationContextScorer.php` should autoload like `RankingContract`. If a future branch adds manual plugin bootstrap requires, register the file in the same bootstrap path as `RankingContract` before running scorer tests.

- [ ] **Step 2: Add the scorer class**

Create `inc/Support/RecommendationContextScorer.php` with deterministic scoring. The implementation must:

- Sanitize `surface` with `sanitize_key()`.
- Normalize all evidence and penalty values through a private `score_value()` method that clamps to `0.0..1.0`.
- Use `0.55` for unknown neutral evidence.
- Tokenize prompt, label, description, category, operation paths, pattern names, section role, template type, and template-part area with lowercase alphanumeric words of length at least 3.
- Cap each source string at 500 characters before tokenization.
- Cap total tokens per side at 100.
- Use unique tokens for weighted prompt-coverage scoring.
- Never include raw tokens, raw prompts, raw generated text, or context text in returned evidence, penalties, ranking metadata, or diagnostics.
- Score prompt match from token overlap without letting long suggestion/context text dilute direct prompt matches: blank prompt returns `0.55`; otherwise compute `prompt_coverage = overlap / prompt_token_count` and `candidate_coverage = overlap / candidate_token_count` using `0.0` candidate coverage when the candidate side has no tokens, then return `0.8 * prompt_coverage + 0.2 * candidate_coverage`, clamped and rounded through `score_value()`. This is intentionally not raw Jaccard because the candidate side includes label, description, category, operations, and context tokens.
- Apply `weak_prompt_match: 0.12` only when the prompt is non-empty and computed `prompt_match < 0.35`.
- Score docs freshness from public docs summaries:
  - `grounded` with `current` freshness or current release coverage: `1.0`
  - `grounded`: `0.9`
  - `degraded`: `0.75`
  - `stale`: `0.45` plus `stale_docs` penalty `0.15`
  - anything else: `0.55`
- Score preset fit as `1.0` for style operations with `valueType: preset`, `0.75` for style operations that omit a style value, `0.45` for style operations with freeform values when matching presets exist, and `0.65` for style operations with freeform values when no matching preset family exists. If no style operation or style value is involved, return neutral `0.55`.
- Score operations fit as `1.0` for accepted operations/changes/attribute updates, `0.7` for advisory pattern/block hints, `0.55` for pure advisory text, and `0.25` when rejected operations exist.
- Apply `validation_risk: 0.15` when `rejectedOperations` is non-empty or sanitized validation/rejection metadata indicates an invalid, stale, unsupported, unsafe, contrast, or accessibility rejection. Do not return validation messages or raw rejection text in scoring output.
- Score support fit from surviving parser output plus known support context:
  - Canonicalize support paths before comparison: array paths like `[ 'color', 'background' ]`, dot strings like `color.background`, block `attributeUpdates.style.color.background`, and operation `path` arrays must all compare as the same dot-path form.
  - `1.0` when an operation path is present in `supportedStylePaths`, when no concrete path can be extracted but the block update panel is allowed by `executionContract.allowedPanels`, or when template/template-part operation uses an allowed area/pattern.
  - `0.45` plus `unsupported_control` penalty `0.2` when an operation references a style path, panel, area, or pattern absent from known context.
  - `0.55` when no support inventory is available.
  - If explicit `styleSupportPaths` inventory exists and a concrete style path can be extracted, the concrete path result wins over broad panel support. Broad `allowedPanels` is a fallback only when no concrete path exists.
- Score section role match by comparing `designSemantics.sectionRole`, block structural identity role/location, template type, template-part area, and suggestion tokens.
- Score pattern readiness as `1.0` when suggested pattern names exist in `context.patterns`, `context.visiblePatternNames`, or block operation allowed patterns; `0.45` when pattern suggestions are present but not visible/known; `0.55` when no pattern is involved.
- Score visible scope match as `1.0` when suggested pattern or target names are visible in the current context, `0.55` when the surface has no visible-scope inventory, and `0.45` when a suggestion points outside visible scope.
- Score accessibility fit as `0.9` only when suggestion tokens address accessibility/contrast/readability/focus/keyboard concerns or when both context and suggestion share relevant accessibility tokens. Do not boost a generic suggestion solely because `designSemantics.mainDesignIssue` is `contrast`. Return `0.45` plus `validation_risk: 0.15` when rejected operations or sanitized validation hints include contrast/accessibility rejection signals; return `0.55` otherwise.
- Score design semantics fit as `0.85` when suggestion tokens match `mainDesignIssue`, `sectionRole`, `contrastContext`, `layoutRhythm`, or `typographyRole`; `0.55` otherwise. Return `0.35` only for direct, enumerated contradictions: low-contrast context plus tokens such as `reduce contrast` or `low contrast`; legibility or too-small text context plus tokens such as `smaller`, `tiny`, or `decrease font`; crowded/overflow context plus tokens such as `dense`, `tight`, or `add more`.
- Detect possible no-ops by comparing `suggestion.attributeUpdates` with `context.block.currentAttributes` and by comparing style operation values to `styleContext.currentConfig.styles`; apply `possible_no_op` penalty `0.25` only when all proposed changes exactly match current state after shallow scalar normalization.
- Do not mark no-ops for partial matches where at least one proposed shallow scalar changes, preset-to-raw-color equivalence, numeric-string versus numeric equivalence inside complex structures, unordered array equivalence, missing current values, or missing default values in V1. Those belong in later design diagnosis/ranking phases.
- Compute final score with `min( 0.35, $penalty_sum )`; individual penalties may sum above `0.35` but the aggregate subtraction must not.

- [ ] **Step 3: Run the scorer test**

Run:

```bash
composer run test:php -- --filter RecommendationContextScorerTest
```

Expected: PASS.

## Task 3: Extend Ranking Metadata Contract

**Files:**

- Modify: `inc/Support/RankingContract.php`
- Modify: `tests/phpunit/RankingContractTest.php`
- Modify: `inc/Abilities/Registration.php`
- Modify: `tests/phpunit/RegistrationTest.php`
- Modify: `tests/phpunit/PatternAbilitiesTest.php`

- [ ] **Step 1: Add ranking metadata tests**

In `tests/phpunit/RankingContractTest.php`, add:

```php
public function test_normalize_preserves_contextual_ranking_component_metadata(): void {
	$result = RankingContract::normalize(
		[],
		[
			'score'              => 0.82,
			'modelScore'         => 0.7,
			'deterministicScore' => 0.9,
			'contextScore'       => 0.8,
			'blendedScore'       => 0.82,
			'contextEvidence'    => [
				'prompt_match' => 0.9,
			],
			'contextPenalties'   => [
				'possible_no_op' => 0.0,
			],
			'rankingVersion'     => 'contextual-ranking-v1',
		]
	);

	$this->assertSame( 0.82, $result['score'] );
	$this->assertSame( 0.7, $result['modelScore'] );
	$this->assertSame( 0.9, $result['deterministicScore'] );
	$this->assertSame( 0.8, $result['contextScore'] );
	$this->assertSame( 0.82, $result['blendedScore'] );
	$this->assertSame( [ 'prompt_match' => 0.9 ], $result['contextEvidence'] );
	$this->assertSame( [ 'possible_no_op' => 0.0 ], $result['contextPenalties'] );
	$this->assertSame( 'contextual-ranking-v1', $result['rankingVersion'] );
}

public function test_normalize_prefers_plugin_generated_component_defaults_over_model_metadata(): void {
	$result = RankingContract::normalize(
		[
			'modelScore'         => 0.01,
			'deterministicScore' => 0.02,
			'contextScore'       => 0.03,
			'blendedScore'       => 0.04,
			'contextEvidence'    => [
				'prompt_match' => 0.01,
			],
			'contextPenalties'   => [
				'stale_docs' => 1.0,
			],
			'rankingVersion'     => 'model-supplied-version',
		],
		[
			'score'              => 0.82,
			'modelScore'         => 0.7,
			'deterministicScore' => 0.9,
			'contextScore'       => 0.8,
			'blendedScore'       => 0.82,
			'contextEvidence'    => [
				'prompt_match' => 0.9,
			],
			'contextPenalties'   => [
				'possible_no_op' => 0.0,
			],
			'rankingVersion'     => 'contextual-ranking-v1',
		]
	);

	$this->assertSame( 0.7, $result['modelScore'] );
	$this->assertSame( 0.9, $result['deterministicScore'] );
	$this->assertSame( 0.8, $result['contextScore'] );
	$this->assertSame( 0.82, $result['blendedScore'] );
	$this->assertSame( [ 'prompt_match' => 0.9 ], $result['contextEvidence'] );
	$this->assertSame( [ 'possible_no_op' => 0.0 ], $result['contextPenalties'] );
	$this->assertSame( 'contextual-ranking-v1', $result['rankingVersion'] );
}

public function test_normalize_ignores_contextual_component_metadata_from_model_input_without_plugin_defaults(): void {
	$result = RankingContract::normalize(
		[
			'score'              => 0.64,
			'modelScore'         => 0.01,
			'deterministicScore' => 0.02,
			'contextScore'       => 0.03,
			'blendedScore'       => 0.04,
			'contextEvidence'    => [
				'prompt_match' => 0.01,
			],
			'contextPenalties'   => [
				'stale_docs' => 1.0,
			],
			'rankingVersion'     => 'contextual-ranking-v1',
		],
		[]
	);

	$this->assertSame( 0.64, $result['score'] );
	$this->assertArrayNotHasKey( 'modelScore', $result );
	$this->assertArrayNotHasKey( 'deterministicScore', $result );
	$this->assertArrayNotHasKey( 'contextScore', $result );
	$this->assertArrayNotHasKey( 'blendedScore', $result );
	$this->assertArrayNotHasKey( 'contextEvidence', $result );
	$this->assertArrayNotHasKey( 'contextPenalties', $result );
	$this->assertArrayNotHasKey( 'rankingVersion', $result );
}

public function test_normalize_bounds_contextual_numeric_maps_and_drops_raw_payloads(): void {
	$result = RankingContract::normalize(
		[],
		[
			'score'            => 0.82,
			'contextEvidence'  => [
				'prompt_match'   => 1.2,
				'raw_text'       => 'Use secret launch copy',
				'nested_payload' => [ 'label' => 'Use secret launch copy' ],
				'bad key!'       => 0.4,
			],
			'contextPenalties' => [
				'stale_docs'      => -1,
				'possible_no_op'  => 0.25,
				'raw_text'        => 'Use secret launch copy',
				'nested_payload'  => [ 'label' => 'Use secret launch copy' ],
			],
			'rankingVersion'   => 'contextual-ranking-v1',
		]
	);

	$this->assertSame( [ 'prompt_match' => 1.0 ], $result['contextEvidence'] );
	$this->assertSame(
		[
			'stale_docs'     => 0.0,
			'possible_no_op' => 0.25,
		],
		$result['contextPenalties']
	);
	$this->assertStringNotContainsString( 'secret', wp_json_encode( $result ) );
}
```

In `tests/phpunit/PatternAbilitiesTest.php`, add a runtime regression that proves pattern ranker output cannot spoof contextual ranking metadata while the shared output schema remains compatible:

```php
public function test_recommend_patterns_does_not_emit_contextual_ranking_metadata_from_ranker_input(): void {
	$this->configure_backends();
	WordPressTestState::$options[ Config::OPTION_PATTERN_RETRIEVAL_BACKEND ] = Config::PATTERN_BACKEND_QDRANT;
	$this->save_index_state();

	WordPressTestState::$remote_post_responses = [
		$this->embedding_response( [ 0.12, 0.34 ] ),
		$this->qdrant_points_response(
			[
				$this->pattern_point( 'theme/hero', 0.81 ),
			]
		),
		$this->ranking_response(
			wp_json_encode(
				[
					'recommendations' => [
						[
							'name'    => 'theme/hero',
							'score'   => 0.9,
							'reason'  => 'Matches the current context.',
							'ranking' => [
								'contextScore'     => 1.0,
								'contextEvidence'  => [
									'prompt_match' => 1.0,
								],
								'contextPenalties' => [
									'stale_docs' => 1.0,
								],
								'rankingVersion'   => 'contextual-ranking-v1',
							],
						],
					],
				]
			)
		),
	];

	$result = $this->recommend_patterns(
		[
			'postType' => 'page',
		],
		[ 'theme/hero' ]
	);

	$this->assertSame( [ 'theme/hero' ], array_column( $result['recommendations'], 'name' ) );
	$ranking = $result['recommendations'][0]['ranking'] ?? [];
	$this->assertIsArray( $ranking );
	$this->assertArrayNotHasKey( 'contextScore', $ranking );
	$this->assertArrayNotHasKey( 'contextEvidence', $ranking );
	$this->assertArrayNotHasKey( 'contextPenalties', $ranking );
	$this->assertArrayNotHasKey( 'rankingVersion', $ranking );
	$source_signals = is_array( $ranking['sourceSignals'] ?? null ) ? $ranking['sourceSignals'] : [];
	$this->assertContains( 'qdrant_semantic', $source_signals );
	$this->assertContains( 'llm_ranker', $source_signals );
	$this->assertNotContains( 'contextual_ranking_v1', $source_signals );
}
```

In `tests/phpunit/RegistrationTest.php`, extend `test_register_abilities_exposes_ranking_contract_in_all_recommendation_surfaces()` to assert these properties on block, style, template, template-part, navigation, and the existing pattern recommendation output schema. Pattern schema coverage is required because `Registration::ranking_contract_schema()` is shared; it documents schema compatibility only and must not add dedicated PatternRecommender shelf ranking in this phase:

```php
$this->assertSame( [ 'number', 'null' ], $ranking['properties']['modelScore']['type'] ?? null );
$this->assertSame( 'number', $ranking['properties']['deterministicScore']['type'] ?? null );
$this->assertSame( 'number', $ranking['properties']['contextScore']['type'] ?? null );
$this->assertSame( 'number', $ranking['properties']['blendedScore']['type'] ?? null );
$this->assertSame( 'object', $ranking['properties']['contextEvidence']['type'] ?? null );
$this->assertSame( 'object', $ranking['properties']['contextPenalties']['type'] ?? null );
$this->assertSame( 'string', $ranking['properties']['rankingVersion']['type'] ?? null );
```

Use explicit output-schema paths so the test proves every affected public contract:

```php
$ranking_schema_paths = [
	'flavor-agent/recommend-block'         => [
		[ 'block', 'items', 'ranking' ],
		[ 'settings', 'items', 'ranking' ],
		[ 'styles', 'items', 'ranking' ],
	],
	'flavor-agent/recommend-style'         => [ [ 'suggestions', 'items', 'ranking' ] ],
	'flavor-agent/recommend-template'      => [ [ 'suggestions', 'items', 'ranking' ] ],
	'flavor-agent/recommend-template-part' => [ [ 'suggestions', 'items', 'ranking' ] ],
	'flavor-agent/recommend-navigation'    => [ [ 'suggestions', 'items', 'ranking' ] ],
	'flavor-agent/recommend-patterns'      => [ [ 'recommendations', 'items', 'ranking' ] ],
];

foreach ( $ranking_schema_paths as $ability_id => $paths ) {
	$output_schema = WordPressTestState::$registered_abilities[ $ability_id ]['output_schema'] ?? [];
	$this->assertIsArray( $output_schema, "Expected output schema for {$ability_id}." );

	foreach ( $paths as $path ) {
		$ranking = self::schema_property_at_path( $output_schema, $path );
		$this->assert_contextual_ranking_schema_fields( $ranking, "{$ability_id} " . implode( '.', $path ) );
	}
}
```

Add private test helpers if they do not already exist:

```php
private static function schema_property_at_path( array $schema, array $path ): array {
	$current = $schema;

	foreach ( $path as $segment ) {
		if ( 'items' === $segment ) {
			$current = $current['items'] ?? [];
			continue;
		}

		$current = $current['properties'][ $segment ] ?? $current[ $segment ] ?? [];
	}

	return is_array( $current ) ? $current : [];
}

private function assert_contextual_ranking_schema_fields( array $ranking, string $message ): void {
	$this->assertSame( [ 'number', 'null' ], $ranking['properties']['modelScore']['type'] ?? null, $message );
	$this->assertSame( 'number', $ranking['properties']['deterministicScore']['type'] ?? null, $message );
	$this->assertSame( 'number', $ranking['properties']['contextScore']['type'] ?? null, $message );
	$this->assertSame( 'number', $ranking['properties']['blendedScore']['type'] ?? null, $message );
	$this->assertSame( 'object', $ranking['properties']['contextEvidence']['type'] ?? null, $message );
	$this->assertSame( 'object', $ranking['properties']['contextPenalties']['type'] ?? null, $message );
	$this->assertSame( 'string', $ranking['properties']['rankingVersion']['type'] ?? null, $message );
}
```

- [ ] **Step 2: Run the failing metadata tests**

Run:

```bash
composer run test:php -- --filter 'RankingContractTest|RegistrationTest|PatternAbilitiesTest'
```

Expected: FAIL because the new fields are not normalized or declared yet, and because ranker-supplied contextual component metadata is not yet blocked from pattern output.

- [ ] **Step 3: Preserve component metadata in `RankingContract::normalize()`**

Update `RankingContract::normalize()` to:

- Add one source of truth for plugin-owned contextual ranking keys:

```php
public const PLUGIN_COMPONENT_KEYS = [
	'modelScore',
	'deterministicScore',
	'contextScore',
	'blendedScore',
	'contextEvidence',
	'contextPenalties',
	'rankingVersion',
];
```

- Preserve `modelScore` as `float|null`.
- Preserve `deterministicScore`, `contextScore`, and `blendedScore` as clamped floats when present.
- Preserve `contextEvidence` and `contextPenalties` only through a dedicated numeric map normalizer:

```php
/**
 * @param array<int, string> $allowed_keys
 * @return array<string, float>
 */
private static function normalize_numeric_ranking_map( mixed $value, array $allowed_keys = [] ): array
```

The helper must sanitize keys with `sanitize_key()`, optionally restrict keys to known evidence or penalty keys, clamp numeric values to `0.0..1.0`, cap maps at 12 entries, and drop strings, arrays, objects, and unknown raw payloads. Do not use `sanitize_structured_value()` for `contextEvidence` or `contextPenalties`.
- Preserve `rankingVersion` as a sanitized string.
- Treat `modelScore`, `deterministicScore`, `contextScore`, `blendedScore`, `contextEvidence`, `contextPenalties`, and `rankingVersion` as plugin-generated fields. When both `$input` and `$defaults` provide those keys, `$defaults` must win so provider/model text cannot spoof plugin component scores or diagnostics.
- Ignore `modelScore`, `deterministicScore`, `contextScore`, `blendedScore`, `contextEvidence`, `contextPenalties`, and `rankingVersion` when they appear only in `$input`. These fields must be emitted only by plugin code through `$defaults`, which keeps `PatternAbilities::recommend_patterns()` from echoing contextual metadata supplied by the pattern ranker.
- Keep `score` as the primary backward-compatible blended ranking value.
- Use `RankingContract::PLUGIN_COMPONENT_KEYS` anywhere parsers or tests need to strip model-supplied contextual component fields, instead of repeating the key list by hand.

Do not change `RankingContract::blend_score()` weights in this phase.

- [ ] **Step 4: Extend public output schema**

Update `Registration::ranking_contract_schema()` with:

```php
'modelScore'         => [
	'type'    => [ 'number', 'null' ],
	'minimum' => 0,
	'maximum' => 1,
],
'deterministicScore' => [
	'type'    => 'number',
	'minimum' => 0,
	'maximum' => 1,
],
'contextScore'       => [
	'type'    => 'number',
	'minimum' => 0,
	'maximum' => 1,
],
'blendedScore'       => [
	'type'    => 'number',
	'minimum' => 0,
	'maximum' => 1,
],
'contextEvidence'    => self::open_object_schema(),
'contextPenalties'   => self::open_object_schema(),
'rankingVersion'     => [ 'type' => 'string' ],
```

Update the schema description to say the additional component fields are plugin-generated, not model-generated.

Because `flavor-agent/recommend-patterns` uses the shared helper for its existing `ranking` field, leave the pattern output schema open to the same optional component fields but do not change `PatternAbilities::recommend_patterns()` to compute or emit `contextual-ranking-v1` metadata in this phase.

- [ ] **Step 5: Run metadata tests**

Run:

```bash
composer run test:php -- --filter 'RankingContractTest|RegistrationTest|PatternAbilitiesTest'
```

Expected: PASS. `PatternAbilitiesTest` must prove a ranker-supplied `ranking.contextScore`, `ranking.contextEvidence`, `ranking.contextPenalties`, or `rankingVersion` does not survive in pattern recommendation output.

- [ ] **Step 6: Prove dedicated pattern recommendations emit plugin-generated contextual ranking**

Run:

```bash
rg -n "RecommendationContextScorer|contextual_ranking_v1|contextual-ranking-v1" inc/Abilities/PatternAbilities.php tests/phpunit/PatternAbilitiesTest.php
composer run test:php -- --filter PatternAbilitiesTest
```

Expected: PASS. `inc/Abilities/PatternAbilities.php` should import and call `RecommendationContextScorer`, pattern recommendation output should include plugin-generated `contextScore`, `blendedScore`, `rankingVersion: contextual-ranking-v1`, and `contextual_ranking_v1`, and `PatternAbilitiesTest` should still prove model/provider-supplied contextual component fields cannot spoof plugin-owned defaults.

## Task 4: Wire Style, Template, Template-Part, and Navigation Parsers

**Files:**

- Modify: `inc/LLM/StylePrompt.php`
- Modify: `inc/LLM/TemplatePrompt.php`
- Modify: `inc/LLM/TemplatePartPrompt.php`
- Modify: `inc/LLM/NavigationPrompt.php`
- Modify: `inc/Abilities/StyleAbilities.php`
- Modify: `inc/Abilities/TemplateAbilities.php`
- Modify: `inc/Abilities/NavigationAbilities.php`
- Modify: `tests/phpunit/StyleAbilitiesTest.php`
- Modify: `tests/phpunit/TemplateAbilitiesTest.php`
- Modify: `tests/phpunit/NavigationAbilitiesTest.php`

- [ ] **Step 1: Add parser method signatures**

Change parser signatures to accept optional ranking context:

```php
public static function parse_response( string $raw, array $context = [], array $ranking_context = [] ): array|\WP_Error
```

Use the same shape for style, template, template-part, and navigation. Existing tests that pass only `$raw` or `$raw, $context` must continue to work.

- [ ] **Step 2: Use context score in each parser**

In each parser, replace:

```php
$computed_score = RankingContract::blend_score(
	[
		'model'         => $model_score,
		'deterministic' => $deterministic_score,
		'context'       => null,
	]
);
```

with:

```php
$context_result = RecommendationContextScorer::score(
	[
		'surface'       => $surface,
		'suggestion'    => $entry,
		'context'       => $context,
		'prompt'        => is_string( $ranking_context['prompt'] ?? null ) ? $ranking_context['prompt'] : '',
		'docsGrounding' => is_array( $ranking_context['docsGrounding'] ?? null ) ? $ranking_context['docsGrounding'] : [],
	]
);

$computed_score = RankingContract::blend_score(
	[
		'model'         => $model_score,
		'deterministic' => $deterministic_score,
		'context'       => $context_result['score'],
	]
);
```

Set `$surface` to:

- `style`
- `template`
- `template-part`
- `navigation`

- [ ] **Step 3: Emit component metadata**

In each parser's `RankingContract::normalize()` defaults, add:

```php
'modelScore'         => $model_score,
'deterministicScore' => $deterministic_score,
'contextScore'       => $context_result['score'],
'blendedScore'       => $computed_score,
'contextEvidence'    => $context_result['evidence'],
'contextPenalties'   => $context_result['penalties'],
'rankingVersion'     => RecommendationContextScorer::VERSION,
```

Also append `contextual_ranking_v1` to `sourceSignals`.

Before passing `$ranking_metadata` into `RankingContract::normalize()`, unset any model-supplied plugin component keys:

```php
foreach ( RankingContract::PLUGIN_COMPONENT_KEYS as $plugin_owned_key ) {
	unset( $ranking_metadata[ $plugin_owned_key ] );
}
```

When adding `contextual_ranking_v1` to `sourceSignals`, normalize through `RankingContract::normalize()` or `array_values( array_unique( ... ) )` so rerank and parse paths do not duplicate the signal. Add at least one parser-family regression proving a model-supplied `contextScore`, `contextEvidence`, or `rankingVersion` does not override the plugin-generated values and that `sourceSignals` contains `contextual_ranking_v1` at most once.

- [ ] **Step 4: Pass prompt and docs summaries from abilities**

Update ability calls:

```php
$payload = StylePrompt::parse_response(
	$result,
	$context,
	[
		'prompt'        => $prompt,
		'docsGrounding' => DocsGuidanceResult::public_summary( $docs_result ),
	]
);
```

Apply the same shape for:

- `TemplatePrompt::parse_response()`
- `TemplatePartPrompt::parse_response()`
- `NavigationPrompt::parse_response()`

- [ ] **Step 5: Add ability-level context propagation regressions**

Add focused ability tests proving the ability methods pass ranking context into their parser calls:

- `StyleAbilitiesTest`: a style recommendation response includes `ranking.contextScore`, `ranking.contextEvidence`, `ranking.contextPenalties`, and `rankingVersion` derived from the request prompt and docs summary.
- `TemplateAbilitiesTest`: both `recommend_template()` and `recommend_template_part()` include contextual ranking metadata and preserve prompt/docs-driven ordering when model scores alone would choose the weaker suggestion.
- `NavigationAbilitiesTest`: existing parser coverage is extended with an ability-level assertion that `recommend_navigation()` forwards prompt/docs ranking context, not only the structural navigation context.

These tests may use the repo's existing provider/client test doubles, but they must assert the ability output shape, not only parser output.

- [ ] **Step 6: Run focused parser and ability tests**

Run:

```bash
composer run test:php -- --filter 'StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest'
```

Expected: existing tests pass after updating any exact ranking score assertions to the new blended score values.

## Task 5: Wire Block Parser and Final Rerank

**Files:**

- Modify: `inc/LLM/Prompt.php`
- Modify: `inc/Abilities/BlockAbilities.php`
- Modify: `tests/phpunit/PromptRulesTest.php`
- Modify: `tests/phpunit/BlockAbilitiesTest.php`

- [ ] **Step 1: Add context-aware block parse signature**

Change:

```php
public static function parse_response( string $raw ): array|\WP_Error
```

to:

```php
public static function parse_response( string $raw, array $context = [], array $ranking_context = [] ): array|\WP_Error
```

Update calls to `validate_suggestions()` inside `parse_response()`:

```php
self::validate_suggestions(
	self::normalize_response_suggestion_list( $data['settings'] ?? null, 'settings' ),
	'settings',
	$context,
	$ranking_context
)
```

Apply the same pattern for `styles` and `block`.

- [ ] **Step 2: Update `validate_suggestions()`**

Change:

```php
private static function validate_suggestions( array $suggestions, string $group ): array
```

to:

```php
private static function validate_suggestions( array $suggestions, string $group, array $context = [], array $ranking_context = [] ): array
```

Use `RecommendationContextScorer::score()` with:

```php
$context_result = RecommendationContextScorer::score(
	[
		'surface'           => 'block',
		'group'             => $group,
		'suggestion'        => $normalized,
		'context'           => $context,
		'prompt'            => is_string( $ranking_context['prompt'] ?? null ) ? $ranking_context['prompt'] : '',
		'docsGrounding'     => is_array( $ranking_context['docsGrounding'] ?? null ) ? $ranking_context['docsGrounding'] : [],
		'executionContract' => is_array( $ranking_context['executionContract'] ?? null ) ? $ranking_context['executionContract'] : [],
	]
);
```

Replace `context => null` in the `blend_score()` call with `$context_result['score']`, and emit the component metadata listed in Task 4.

Before passing `$ranking_metadata` into `RankingContract::normalize()`, unset `RankingContract::PLUGIN_COMPONENT_KEYS` so block model output cannot spoof `contextScore`, `contextEvidence`, or `rankingVersion`.

- [ ] **Step 3: Add final rerank helper after block enforcement**

Add this public helper to `Prompt`:

```php
/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $context
 * @param array<string, mixed> $ranking_context
 * @return array<string, mixed>
 */
public static function rerank_payload( array $payload, array $context = [], array $ranking_context = [] ): array {
	foreach ( [ 'settings', 'styles', 'block' ] as $group ) {
		if ( ! is_array( $payload[ $group ] ?? null ) ) {
			continue;
		}

		$payload[ $group ] = self::rerank_suggestions(
			$payload[ $group ],
			$group,
			$context,
			$ranking_context
		);
	}

	return $payload;
}
```

Implement private `rerank_suggestions()` so it:

- Reads `modelScore` and `deterministicScore` from existing ranking metadata.
- Uses robust fallbacks for legacy or malformed payloads:
  - `modelScore`: existing `ranking.modelScore` when numeric, else existing `ranking.score` when numeric, else `null`.
  - `deterministicScore`: existing `ranking.deterministicScore` when numeric, else existing `ranking.score` when numeric, else neutral `0.55`.
  - `contextScore`: always recompute from final `operations` and `rejectedOperations`.
  - Plugin-owned component fields are always regenerated from `$defaults`, never preserved from payload input.
- Recomputes `contextScore` with final `operations` and `rejectedOperations`.
- Re-blends `model`, `deterministic`, and `context`.
- Replaces ranking component fields while preserving model-supplied `reason`, `designPrinciple`, and `risk`.
- Sorts by recomputed score descending and preserves previous order as tiebreaker.
- Ignores any model-supplied plugin component fields when recomputing the final ranking metadata.

- [ ] **Step 4: Call rerank after enforcement**

In `BlockAbilities::recommend_block()`, change parse/enforcement flow to:

```php
$ranking_context = [
	'prompt'            => $prompt,
	'docsGrounding'     => DocsGuidanceResult::public_summary( $docs_result ),
	'executionContract' => $execution_contract,
];

$payload = Prompt::parse_response( $result, $context, $ranking_context );
```

After `Prompt::enforce_block_context_rules()` succeeds, call:

```php
$payload = Prompt::rerank_payload( $payload, $context, $ranking_context );
```

Keep `preFilteringCounts` from before enforcement.

- [ ] **Step 5: Add post-enforcement rerank regressions**

In `tests/phpunit/PromptRulesTest.php`, add a direct `Prompt::rerank_payload()` regression that starts from an already parsed payload containing two block suggestions:

- one high model-score suggestion with final `rejectedOperations`,
- one lower model-score suggestion with final accepted `operations`.

Assert the accepted operation moves first after rerank, `ranking.contextPenalties.validation_risk` is greater for the rejected suggestion, and model-supplied plugin component fields do not override recomputed values.

Add a legacy-payload rerank case where one suggestion has only `ranking.score` and no `modelScore`/`deterministicScore`; assert rerank still emits fresh `modelScore`, `deterministicScore`, `contextScore`, `blendedScore`, `contextEvidence`, `contextPenalties`, and `rankingVersion` without notices.

In `tests/phpunit/BlockAbilitiesTest.php`, add an ability-level regression that exercises the production flow through `BlockAbilities::recommend_block()` with a mocked block recommendation response. Assert the returned payload has:

- parser metadata generated from prompt/docs/execution context,
- final ranking order after `Prompt::enforce_block_context_rules()` and `Prompt::rerank_payload()`,
- unchanged `preFilteringCounts` from before enforcement.

- [ ] **Step 6: Run focused block parser and ability tests**

Run:

```bash
composer run test:php -- --filter 'PromptRulesTest|BlockAbilitiesTest'
```

Expected: PASS after updating exact ranking assertions.

## Task 6: Persist Compact Ranking Snapshots in Local Outcome Diagnostics

**Files:**

- Modify: `src/store/recommendation-outcomes.js`
- Modify: `src/store/__tests__/recommendation-outcomes.test.js`
- Modify: `inc/Activity/RecommendationOutcome.php`
- Modify: `tests/phpunit/RecommendationOutcomeTest.php`
- Modify: `docs/reference/activity-state-machine.md`

- [ ] **Step 1: Add JS outcome snapshot normalization**

In `src/store/recommendation-outcomes.js`, add:

```js
const RANKING_SET_CAP = 3;

function normalizeRankingSnapshot( ranking = null ) {
	if ( ! ranking || typeof ranking !== 'object' ) {
		return null;
	}

	const numberOrNull = ( value ) =>
		Number.isFinite( value ) ? Math.max( 0, Math.min( 1, value ) ) : null;
	const numericMap = ( value = {} ) =>
		Object.fromEntries(
			Object.entries( value || {} )
				.filter( ( [ key, entry ] ) => /^[a-z0-9_-]+$/i.test( key ) && Number.isFinite( entry ) )
				.slice( 0, 12 )
				.map( ( [ key, entry ] ) => [ key, Math.max( 0, Math.min( 1, entry ) ) ] )
		);

	return {
		rankingVersion: cleanCode( ranking.rankingVersion ),
		modelScore: numberOrNull( ranking.modelScore ),
		deterministicScore: numberOrNull( ranking.deterministicScore ),
		contextScore: numberOrNull( ranking.contextScore ),
		blendedScore: numberOrNull( ranking.blendedScore ?? ranking.score ),
		contextEvidence: numericMap( ranking.contextEvidence ),
		contextPenalties: numericMap( ranking.contextPenalties ),
	};
}

function normalizeRankingSuggestionKey( value = '', fallback = '' ) {
	const key = cleanString( value );
	if ( /^[A-Za-z0-9:_./-]+$/.test( key ) ) {
		return key;
	}

	return cleanString( fallback );
}

function normalizeRankingSet( suggestions = [] ) {
	if ( ! Array.isArray( suggestions ) ) {
		return [];
	}

	return suggestions
		.slice( 0, RANKING_SET_CAP )
		.map( ( suggestion, index ) => {
			if ( ! suggestion || typeof suggestion !== 'object' ) {
				return null;
			}

			const ranking = normalizeRankingSnapshot( suggestion.ranking );
			const suggestionKey =
				normalizeRankingSuggestionKey(
					suggestion?.recommendationOutcome?.suggestionKey,
					''
				) ||
				normalizeRankingSuggestionKey(
					suggestion.suggestionKey,
					`suggestion:${ index + 1 }`
				);

			if ( ! ranking || ! suggestionKey ) {
				return null;
			}

			return {
				suggestionKey,
				rank: index + 1,
				rankingVersion: ranking.rankingVersion,
				contextScore: ranking.contextScore,
				blendedScore: ranking.blendedScore,
			};
		} )
		.filter( Boolean );
}
```

`normalizeRankingSet()` must not call `getSuggestionOutcomeKey()` because that helper can derive fallback identity from suggestion labels or other generated fields. For ranking diagnostics, `suggestionKey` must be an existing stable key, an existing `recommendationOutcome.suggestionKey`, or a deterministic set-local fallback such as `suggestion:1`; it must not derive from raw label, description, operation detail, block content, or pattern payload.

Include the snapshot in `decorateRecommendationPayload()`:

```js
ranking: normalizeRankingSnapshot( suggestion.ranking ),
```

inside `recommendationOutcome`.

Extend `buildRecommendationIdentityFromSuggestion()` to read `suggestion.recommendationOutcome.ranking`.

Extend `getRecommendationOutcomeSummaryFromPayload()` so aggregate `shown` events carry a bounded result-set ranking summary:

```js
rankingSet: normalizeRankingSet( suggestions ),
```

inside the returned summary object.

Extend `buildRecommendationOutcomeEntry()` with a `rankingSet = []` parameter. Inside `after.outcome`, use separate shapes:

```js
...( safeEvent === 'shown'
	? { rankingSet: normalizeRankingSetFromSummary( rankingSet ) }
	: { ranking: normalizeRankingSnapshot( identity.ranking ) } ),
```

Implement `normalizeRankingSetFromSummary()` with the same cap and score clamping as `normalizeRankingSet()`, but read already-compact entries shaped as `{ suggestionKey, rank, contextScore, blendedScore, rankingVersion }`. Reject or replace any summary `suggestionKey` that contains whitespace or generated prose-like text; allowed diagnostics keys are stable ids such as `suggestion:1`, `block:styles:2`, `theme/hero`, or hash-like identifiers.

- [ ] **Step 2: Add PHP outcome snapshot and ranking-set normalization**

In `RecommendationOutcome::normalize_entry()`, add separate ranking shapes to `$normalized_outcome`:

```php
'ranking'    => 'shown' === $event ? null : self::normalize_ranking_snapshot( $outcome['ranking'] ?? [] ),
'rankingSet' => 'shown' === $event ? self::normalize_ranking_set( $outcome['rankingSet'] ?? [] ) : [],
```

Implement:

```php
/**
 * @return array<string, mixed>|null
 */
private static function normalize_ranking_snapshot( mixed $value ): ?array {
	if ( ! is_array( $value ) ) {
		return null;
	}

	$ranking = [
		'rankingVersion'     => self::bounded_string( $value['rankingVersion'] ?? '', 64 ),
		'modelScore'         => self::normalize_nullable_score( $value['modelScore'] ?? null ),
		'deterministicScore' => self::normalize_nullable_score( $value['deterministicScore'] ?? null ),
		'contextScore'       => self::normalize_nullable_score( $value['contextScore'] ?? null ),
		'blendedScore'       => self::normalize_nullable_score( $value['blendedScore'] ?? null ),
		'contextEvidence'    => self::normalize_numeric_map( $value['contextEvidence'] ?? [] ),
		'contextPenalties'   => self::normalize_numeric_map( $value['contextPenalties'] ?? [] ),
	];

	return array_filter(
		$ranking,
		static fn ( mixed $entry ): bool => null !== $entry && [] !== $entry && '' !== $entry
	);
}
```

Also implement:

```php
/**
 * @return array<int, array<string, mixed>>
 */
private static function normalize_ranking_set( mixed $value ): array {
	if ( ! is_array( $value ) ) {
		return [];
	}

	$set = [];
	foreach ( array_slice( $value, 0, self::TOP_SUGGESTION_CAP ) as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$suggestion_key = self::normalize_ranking_suggestion_key( $entry['suggestionKey'] ?? '' );
		if ( '' === $suggestion_key ) {
			continue;
		}

		$set[] = array_filter(
			[
				'suggestionKey'   => $suggestion_key,
				'rank'            => self::normalize_non_negative_int( $entry['rank'] ?? 0 ),
				'rankingVersion'  => self::bounded_string( $entry['rankingVersion'] ?? '', 64 ),
				'contextScore'    => self::normalize_nullable_score( $entry['contextScore'] ?? null ),
				'blendedScore'    => self::normalize_nullable_score( $entry['blendedScore'] ?? null ),
			],
			static fn ( mixed $item ): bool => null !== $item && '' !== $item
		);
	}

	return $set;
}
```

Add private `normalize_nullable_score()`, `normalize_numeric_map()`, and `normalize_ranking_suggestion_key()` helpers. Scores clamp to `0.0..1.0`; numeric maps sanitize keys with `sanitize_key()` and cap maps at 12 entries; ranking suggestion keys accept only compact stable ids matching `/^[A-Za-z0-9:_\\.\\/-]+$/` and reject whitespace/prose-like keys. After building `$normalized_outcome`, remove empty `ranking` and `rankingSet` entries so non-ranking rows stay compact.

- [ ] **Step 3: Add outcome tests**

In `tests/phpunit/RecommendationOutcomeTest.php`, add a case proving per-suggestion ranking snapshots survive and raw text does not get introduced:

```php
$entry = RecommendationOutcome::normalize_entry(
	[
		'type'    => RecommendationOutcome::TYPE,
		'surface' => 'block',
		'after'   => [
			'outcome' => [
				'event'                  => 'selected_for_review',
				'recommendationSetId'    => 'set-1',
				'sourceRequestSignature' => 'hash_abc123',
				'ranking'                => [
					'rankingVersion'   => 'contextual-ranking-v1',
					'contextScore'     => 0.72,
					'blendedScore'     => 0.81,
					'contextEvidence'  => [
						'prompt_match' => 0.8,
					],
					'contextPenalties' => [
						'possible_no_op' => 0.0,
					],
				],
			],
		],
	]
);

$this->assertSame( 'contextual-ranking-v1', $entry['after']['outcome']['ranking']['rankingVersion'] );
$this->assertSame( 0.72, $entry['after']['outcome']['ranking']['contextScore'] );
$this->assertSame( [ 'prompt_match' => 0.8 ], $entry['after']['outcome']['ranking']['contextEvidence'] );
$this->assertArrayNotHasKey( 'rankingSet', $entry['after']['outcome'] );
```

Add a separate aggregate `shown` case proving it stores `rankingSet`, not one arbitrary suggestion-level `ranking`:

```php
$entry = RecommendationOutcome::normalize_entry(
	[
		'type'    => RecommendationOutcome::TYPE,
		'surface' => 'block',
		'after'   => [
			'outcome' => [
				'event'                  => 'shown',
				'recommendationSetId'    => 'set-1',
				'sourceRequestSignature' => 'hash_abc123',
				'topSuggestionKeys'      => [ 'one', 'two', 'three', 'four' ],
				'ranking'                => [
					'rankingVersion' => 'should-not-survive-on-shown',
				],
				'rankingSet'             => [
					[
						'suggestionKey'  => 'one',
						'rank'           => 1,
						'rankingVersion' => 'contextual-ranking-v1',
						'contextScore'   => 0.72,
						'blendedScore'   => 0.81,
					],
					[
						'suggestionKey'  => 'two',
						'rank'           => 2,
						'rankingVersion' => 'contextual-ranking-v1',
						'contextScore'   => 0.64,
						'blendedScore'   => 0.76,
					],
					[
						'suggestionKey'  => 'three',
						'rank'           => 3,
						'rankingVersion' => 'contextual-ranking-v1',
						'contextScore'   => 0.58,
						'blendedScore'   => 0.7,
					],
					[
						'suggestionKey'  => 'four',
						'rank'           => 4,
						'rankingVersion' => 'contextual-ranking-v1',
						'contextScore'   => 0.5,
						'blendedScore'   => 0.66,
					],
				],
			],
		],
	]
);

$this->assertArrayNotHasKey( 'ranking', $entry['after']['outcome'] );
$this->assertCount( 3, $entry['after']['outcome']['rankingSet'] );
$this->assertSame( 'one', $entry['after']['outcome']['rankingSet'][0]['suggestionKey'] );
$this->assertSame( 0.72, $entry['after']['outcome']['rankingSet'][0]['contextScore'] );
```

Add a PHP privacy regression proving `normalize_entry()` does not preserve generated text inside ranking diagnostics:

```php
$entry = RecommendationOutcome::normalize_entry(
	[
		'type'    => RecommendationOutcome::TYPE,
		'surface' => 'block',
		'after'   => [
			'outcome' => [
				'event'                  => 'shown',
				'recommendationSetId'    => 'set-1',
				'sourceRequestSignature' => 'hash_abc123',
				'rankingSet'             => [
					[
						'suggestionKey'  => 'suggestion:1',
						'rank'           => 1,
						'rankingVersion' => 'contextual-ranking-v1',
						'contextScore'   => 0.72,
						'blendedScore'   => 0.81,
						'label'          => 'Use secret launch copy',
						'description'    => 'Use secret launch copy',
					],
				],
			],
		],
	]
);

$encoded = wp_json_encode( $entry['after']['outcome'] );
$this->assertStringNotContainsString( 'secret', $encoded );
$this->assertStringNotContainsString( 'launch', $encoded );
$this->assertStringNotContainsString( 'copy', $encoded );
$this->assertSame( 'suggestion:1', $entry['after']['outcome']['rankingSet'][0]['suggestionKey'] );
```

In `src/store/__tests__/recommendation-outcomes.test.js`, add matching JS coverage:

- `decorateRecommendationPayload()` copies each suggestion's compact ranking snapshot into `recommendationOutcome.ranking`.
- `getRecommendationOutcomeSummaryFromPayload()` returns a top-three `rankingSet` for aggregate `shown`.
- `buildRecommendationOutcomeEntry( { event: 'shown', rankingSet } )` stores `after.outcome.rankingSet` and omits `after.outcome.ranking`.
- `buildRecommendationOutcomeEntry( { event: 'selected_for_review', suggestion } )` stores one compact `after.outcome.ranking` and omits `after.outcome.rankingSet`.
- `blendedScore: 0` survives normalization through nullish fallback and is not replaced by `ranking.score`.
- A suggestion labeled `"Use secret launch copy"` with no explicit `suggestionKey` produces a `rankingSet` whose keys are set-local fallbacks like `suggestion:1`; assert `JSON.stringify( entry.after.outcome )` does not contain `"secret"`, `"launch"`, or `"copy"`.

- [ ] **Step 4: Update activity docs**

In `docs/reference/activity-state-machine.md`, update the recommendation-outcome bullet to say local diagnostics may include bounded per-suggestion ranking snapshots for selected/blocked/inserted events and bounded aggregate `rankingSet` summaries for `shown` events. Re-state that neither shape stores raw prompts, generated recommendation text, block attributes, post content, validation messages, pattern payloads, or remote telemetry.

- [ ] **Step 5: Run outcome tests**

Run:

```bash
composer run test:php -- --filter RecommendationOutcomeTest
npm run test:unit -- recommendation-outcomes
```

Expected: PASS.

## Task 7: Add Parser-Backed Contextual Ranking Fixtures

**Files:**

- Create: `tests/phpunit/fixtures/recommendation-evaluation-contextual-ranking-fixtures.php`
- Modify: `tests/phpunit/RecommendationEvaluationTest.php`

- [ ] **Step 1: Add fixture file**

Create `tests/phpunit/fixtures/recommendation-evaluation-contextual-ranking-fixtures.php` with fixtures for these scenarios:

```php
<?php

declare(strict_types=1);

return [
	'style_prompt_match_outranks_generic' => [
		'surface'           => 'style',
		'parser'            => 'style',
		'rankedMetricProbe' => true,
		'rankingContext'    => [
			'prompt'        => 'Use the accent background on the hero',
			'docsGrounding' => [
				'status'    => 'grounded',
				'freshness' => [ 'current' ],
			],
		],
		'context'           => [
			'styleContext' => [
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
				],
				'themeTokens'         => [
					'colors'       => [ 'accent: #f5f5f5' ],
					'colorPresets' => [
						[
							'slug'  => 'accent',
							'color' => '#f5f5f5',
						],
					],
				],
				'designSemantics'     => [
					'surface'         => 'global-styles',
					'sectionRole'     => 'hero',
					'mainDesignIssue' => 'contrast',
				],
			],
		],
		'response'          => [
			'suggestions' => [
				[
					'label'       => 'General polish',
					'description' => 'Make a small visual improvement.',
					'category'    => 'style',
					'tone'        => 'advisory',
					'operations'  => [],
					'confidence'  => 0.9,
				],
				[
					'label'       => 'Use accent hero background',
					'description' => 'Apply the accent background preset to the hero area.',
					'category'    => 'color',
					'tone'        => 'executable',
					'operations'  => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'background' ],
							'value'      => 'var:preset|color|accent',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'accent',
						],
					],
					'confidence'  => 0.55,
				],
			],
		],
		'expectedTopLabel'  => 'Use accent hero background',
	],
	'template_role_matched_pattern_outranks_mismatch' => [
		'surface'           => 'template',
		'parser'            => 'template',
		'rankedMetricProbe' => true,
		'rankingContext'    => [
			'prompt' => 'Improve the archive grid',
		],
		'context'           => [
			'templateType'        => 'archive',
			'visiblePatternNames' => [ 'theme/archive-grid' ],
			'patterns'            => [
				[
					'name'  => 'theme/archive-grid',
					'title' => 'Archive Grid',
				],
				[
					'name'  => 'theme/footer-links',
					'title' => 'Footer Links',
				],
			],
			'designSemantics'     => [
				'surface'         => 'template',
				'sectionRole'     => 'archive-list',
				'layoutRhythm'    => 'grid',
				'mainDesignIssue' => 'rhythm',
			],
		],
		'response'          => [
			'suggestions' => [
				[
					'label'              => 'Use footer links pattern',
					'description'        => 'Add footer links.',
					'operations'         => [],
					'patternSuggestions' => [ 'theme/footer-links' ],
					'confidence'         => 0.85,
				],
				[
					'label'              => 'Use archive grid pattern',
					'description'        => 'Match the archive list role with the grid pattern.',
					'operations'         => [],
					'patternSuggestions' => [ 'theme/archive-grid' ],
					'confidence'         => 0.55,
				],
			],
		],
		'expectedTopLabel'  => 'Use archive grid pattern',
	],
	'block_no_op_is_demoted_after_context_check' => [
		'surface'           => 'block',
		'parser'            => 'block',
		'lane'              => 'block',
		'rankedMetricProbe' => true,
		'rankingContext'    => [
			'prompt' => 'Improve heading hierarchy',
		],
		'currentState'      => [
			'attributes' => [
				'level' => 2,
			],
		],
		'context'           => [
			'block' => [
				'name'              => 'core/heading',
				'currentAttributes' => [
					'level' => 2,
				],
			],
			'designSemantics' => [
				'surface'         => 'block',
				'typographyRole'  => 'heading',
				'mainDesignIssue' => 'hierarchy',
			],
		],
		'response'          => [
			'block' => [
				[
					'label'            => 'Keep heading level',
					'description'      => 'Keep the current heading level.',
					'type'             => 'attribute_change',
					'panel'            => 'general',
					'attributeUpdates' => '{"level":2}',
					'operations'       => [],
					'confidence'       => 0.9,
				],
				[
					'label'            => 'Lower hierarchy one step',
					'description'      => 'Set the heading to level three for the section hierarchy.',
					'type'             => 'attribute_change',
					'panel'            => 'general',
					'attributeUpdates' => '{"level":3}',
					'operations'       => [],
					'confidence'       => 0.55,
				],
			],
		],
		'expectedTopLabel'  => 'Lower hierarchy one step',
	],
	'block_supported_operation_outranks_rejected_operation' => [
		'surface'                      => 'block',
		'parser'                       => 'block',
		'lane'                         => 'block',
		'rankedMetricProbe'            => true,
		'enableBlockStructuralActions' => true,
		'rankingContext'               => [
			'prompt' => 'Insert the hero pattern after the selected group',
		],
		'context'           => [
			'block'                 => [
				'name' => 'core/group',
			],
			'blockOperationContext' => [
				'targetClientId'  => 'block-1',
				'targetBlockName' => 'core/group',
				'targetSignature' => 'sig-1',
				'allowedPatterns' => [
					[
						'name'           => 'theme/hero',
						'title'          => 'Hero',
						'allowedActions' => [ 'insert_after' ],
					],
				],
			],
		],
		'response'          => [
			'block' => [
				[
					'label'       => 'Replace with missing pattern',
					'description' => 'Replace the block with a pattern that is not allowed.',
					'type'        => 'pattern_replacement',
					'operations'  => [
						[
								'type'            => 'replace_block_with_pattern',
								'patternName'     => 'theme/missing',
								'targetClientId'  => 'block-1',
								'targetSignature' => 'sig-1',
								'action'          => 'replace',
						],
					],
					'confidence'  => 0.9,
				],
				[
					'label'       => 'Insert hero pattern after group',
					'description' => 'Use the allowed hero pattern after the selected group.',
					'type'        => 'pattern_replacement',
					'operations'  => [
						[
								'type'            => 'insert_pattern',
								'patternName'     => 'theme/hero',
								'targetClientId'  => 'block-1',
								'targetSignature' => 'sig-1',
								'position'        => 'insert_after',
								'action'          => 'insert_after',
						],
					],
					'confidence'  => 0.55,
				],
			],
		],
		'expectedTopLabel'  => 'Insert hero pattern after group',
	],
	'style_native_preset_outranks_custom_value' => [
		'surface'           => 'style',
		'parser'            => 'style',
		'rankedMetricProbe' => true,
		'context'           => [
			'styleContext' => [
				'supportedStylePaths' => [
					[
						'path'        => [ 'color', 'background' ],
						'valueSource' => 'color',
					],
				],
				'themeTokens'         => [
					'colors'       => [ 'accent: #f5f5f5' ],
					'colorPresets' => [
						[
							'slug'  => 'accent',
							'color' => '#f5f5f5',
						],
					],
				],
			],
		],
		'response'          => [
			'suggestions' => [
				[
					'label'      => 'Use custom background value',
					'category'   => 'color',
					'tone'       => 'executable',
					'operations' => [
						[
							'type'      => 'set_styles',
							'path'      => [ 'color', 'background' ],
							'value'     => '#123456',
							'valueType' => 'freeform',
						],
					],
					'confidence' => 0.9,
				],
				[
					'label'      => 'Use accent background preset',
					'category'   => 'color',
					'tone'       => 'executable',
					'operations' => [
						[
							'type'       => 'set_styles',
							'path'       => [ 'color', 'background' ],
							'value'      => 'var:preset|color|accent',
							'valueType'  => 'preset',
							'presetType' => 'color',
							'presetSlug' => 'accent',
						],
					],
					'confidence' => 0.55,
				],
			],
		],
		'expectedTopLabel'  => 'Use accent background preset',
	],
	'navigation_overlay_context_outranks_generic_cleanup' => [
		'surface'           => 'navigation',
		'parser'            => 'navigation',
		'rankedMetricProbe' => true,
		'rankingContext'    => [
			'prompt'        => 'Tune the overlay navigation for mobile',
			'docsGrounding' => [
				'status'    => 'grounded',
				'freshness' => [ 'current' ],
			],
		],
		'context'           => [
			'location'        => 'header',
			'overlayContext'  => [
				'usesOverlay' => true,
				'overlayMode' => 'mobile',
			],
			'targetInventory' => [
				[
					'path'  => [ 0 ],
					'label' => 'Home',
					'type'  => 'navigation-link',
					'depth' => 0,
				],
			],
		],
		'response'          => [
			'suggestions' => [
				[
					'label'       => 'Generic menu cleanup',
					'description' => 'Clean up the menu.',
					'category'    => 'structure',
					'changes'     => [
						[
							'type'       => 'reorder',
							'targetPath' => [ 0 ],
							'target'     => 'Home',
							'detail'     => 'Move the home link.',
						],
					],
					'confidence'  => 0.9,
				],
				[
					'label'       => 'Tune mobile overlay navigation',
					'description' => 'Adjust the header overlay navigation for the mobile menu.',
					'category'    => 'overlay',
					'changes'     => [
						[
							'type'   => 'set-attribute',
							'target' => 'overlayMenu',
							'detail' => 'Use current overlay navigation behavior.',
						],
					],
					'confidence'  => 0.55,
				],
			],
		],
		'expectedTopLabel'  => 'Tune mobile overlay navigation',
	],
	'template_part_area_match_outranks_mismatch' => [
		'surface'           => 'template-part',
		'parser'            => 'template_part',
		'rankedMetricProbe' => true,
		'rankingContext'    => [
			'prompt' => 'Improve the footer layout',
		],
		'context'           => [
			'templatePartRef'     => 'theme//footer',
			'slug'                => 'footer',
			'area'                => 'footer',
			'visiblePatternNames' => [ 'theme/footer-links' ],
			'patterns'            => [
				[
					'name'  => 'theme/header-links',
					'title' => 'Header Links',
				],
				[
					'name'  => 'theme/footer-links',
					'title' => 'Footer Links',
				],
			],
			'designSemantics'     => [
				'surface'         => 'template-part',
				'sectionRole'     => 'footer',
				'mainDesignIssue' => 'rhythm',
				'templatePart'    => [
					'slug' => 'footer',
					'area' => 'footer',
				],
			],
		],
		'response'          => [
			'suggestions' => [
				[
					'label'              => 'Use header links',
					'description'        => 'Add a header links pattern.',
					'operations'         => [],
					'patternSuggestions' => [ 'theme/header-links' ],
					'confidence'         => 0.9,
				],
				[
					'label'              => 'Use footer links',
					'description'        => 'Use the visible footer links pattern for this footer.',
					'operations'         => [],
					'patternSuggestions' => [ 'theme/footer-links' ],
					'confidence'         => 0.55,
				],
			],
		],
		'expectedTopLabel'  => 'Use footer links',
	],
];
```

The fixture file may add more cases later, but these named cases are the V1 minimum and every case must include `expectedTopLabel`.

- [ ] **Step 2: Teach evaluation test to pass ranking context and production-equivalent block rerank**

Update the imports in `tests/phpunit/RecommendationEvaluationTest.php`:

```php
use FlavorAgent\Context\BlockRecommendationExecutionContract;
```

Update `RecommendationEvaluationTest::materialize_parser_fixture()` so it passes the full fixture into the parser helper:

```php
$parsed = self::parse_fixture_response( $parser, $response, $context, $fixture );
```

Update the helper signature:

```php
private static function parse_fixture_response( string $parser, array $response, array $context, array $fixture = [] ): array {
```

Inside `RecommendationEvaluationTest::parse_fixture_response()`, read:

```php
$ranking_context = is_array( $fixture['rankingContext'] ?? null ) ? $fixture['rankingContext'] : [];
```

Pass it into each parser:

```php
'block'         => Prompt::parse_response( $raw, $context, $ranking_context ),
'style'         => StylePrompt::parse_response( $raw, $context, $ranking_context ),
'template'      => TemplatePrompt::parse_response( $raw, $context, $ranking_context ),
'template_part' => TemplatePartPrompt::parse_response( $raw, $context, $ranking_context ),
'navigation'    => NavigationPrompt::parse_response( $raw, $context, $ranking_context ),
```

For `block` parser fixtures, immediately run the same post-parse ordering path production uses:

```php
$enable_structural_actions = ! empty( $fixture['enableBlockStructuralActions'] );
if ( $enable_structural_actions ) {
	add_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' );
}

try {
	if ( 'block' === $parser && is_array( $parsed ) && empty( $fixture['skipBlockContextEnforcement'] ) ) {
		$execution_contract = is_array( $fixture['executionContract'] ?? null )
			? $fixture['executionContract']
			: BlockRecommendationExecutionContract::from_context( $context );

		$parsed = Prompt::enforce_block_context_rules(
			$parsed,
			is_array( $context['block'] ?? null ) ? $context['block'] : [],
			$execution_contract,
			is_array( $context['blockOperationContext'] ?? null ) ? $context['blockOperationContext'] : []
		);
		self::assertIsArray( $parsed );

		$ranking_context['executionContract'] = $execution_contract;
		$parsed                              = Prompt::rerank_payload( $parsed, $context, $ranking_context );
	}
} finally {
	if ( $enable_structural_actions ) {
		remove_filter( 'flavor_agent_enable_block_structural_actions', '__return_true' );
	}
}
```

This keeps the contextual ranking fixtures aligned with `BlockAbilities::recommend_block()`, where operation acceptance/rejection is only known after `Prompt::enforce_block_context_rules()`. The structural-action filter is deliberately fixture-scoped so the default-off production setting stays unchanged and only fixtures that declare `enableBlockStructuralActions` can prove accepted structural operations.

- [ ] **Step 3: Add top-label assertions**

Add a test method:

```php
public function test_contextual_ranking_parser_fixtures_choose_expected_top_suggestions(): void {
	$fixtures = require __DIR__ . '/fixtures/recommendation-evaluation-contextual-ranking-fixtures.php';

	foreach ( $fixtures as $name => $fixture ) {
		$this->assertIsArray( $fixture );
		$expected_top_label = (string) ( $fixture['expectedTopLabel'] ?? '' );
		$this->assertNotSame( '', $expected_top_label, "{$name} must declare expectedTopLabel." );

		$materialized = self::materialize_parser_fixture( $fixture );
		$suggestions  = is_array( $materialized['suggestions'] ?? null ) ? $materialized['suggestions'] : [];
		$this->assertNotEmpty( $suggestions, "{$name} must materialize at least one suggestion." );

		$this->assertSame(
			$expected_top_label,
			(string) ( $suggestions[0]['label'] ?? '' ),
			"{$name} should put the context-fit suggestion first."
		);

		$ranking = is_array( $suggestions[0]['ranking'] ?? null ) ? $suggestions[0]['ranking'] : [];
			$this->assertSame( 'contextual-ranking-v1', $ranking['rankingVersion'] ?? null, $name );
			$this->assertIsFloat( $ranking['contextScore'] ?? null, $name );
			$this->assertIsArray( $ranking['contextEvidence'] ?? null, $name );

			if ( isset( $suggestions[1] ) ) {
				$top_ranking       = is_array( $suggestions[0]['ranking'] ?? null ) ? $suggestions[0]['ranking'] : [];
				$runner_up_ranking = is_array( $suggestions[1]['ranking'] ?? null ) ? $suggestions[1]['ranking'] : [];
				$component_snapshot = [
					'top'      => [
						'label'              => (string) ( $suggestions[0]['label'] ?? '' ),
						'modelScore'         => $top_ranking['modelScore'] ?? null,
						'deterministicScore' => $top_ranking['deterministicScore'] ?? null,
						'contextScore'       => $top_ranking['contextScore'] ?? null,
						'blendedScore'       => $top_ranking['blendedScore'] ?? null,
					],
					'runnerUp' => [
						'label'              => (string) ( $suggestions[1]['label'] ?? '' ),
						'modelScore'         => $runner_up_ranking['modelScore'] ?? null,
						'deterministicScore' => $runner_up_ranking['deterministicScore'] ?? null,
						'contextScore'       => $runner_up_ranking['contextScore'] ?? null,
						'blendedScore'       => $runner_up_ranking['blendedScore'] ?? null,
					],
				];
				$message = "{$name} ranking components: " . wp_json_encode( $component_snapshot );

				$this->assertGreaterThanOrEqual(
					(float) ( $runner_up_ranking['blendedScore'] ?? 0 ) + 0.01,
					(float) ( $top_ranking['blendedScore'] ?? 0 ),
					$message
				);
				$this->assertGreaterThan(
					(float) ( $runner_up_ranking['contextScore'] ?? 0 ),
					(float) ( $top_ranking['contextScore'] ?? 0 ),
					$message
				);
			}

			if ( ! empty( $fixture['enableBlockStructuralActions'] ) ) {
			$accepted_operations = is_array( $suggestions[0]['operations'] ?? null ) ? $suggestions[0]['operations'] : [];
			$rejected_codes      = array_values(
				array_filter(
					array_map(
						static fn ( array $rejection ): string => (string) ( $rejection['code'] ?? '' ),
						is_array( $suggestions[0]['rejectedOperations'] ?? null ) ? $suggestions[0]['rejectedOperations'] : []
					)
				)
			);

			$this->assertNotEmpty( $accepted_operations, "{$name} must prove an accepted structural operation." );
			$this->assertNotContains( 'block_structural_actions_disabled', $rejected_codes, "{$name} must enable the structural-action fixture precondition." );
		}
	}
}
```

The component snapshot and `0.01` blended-score margin are required so the fixture fails with useful evidence if a future deterministic-score tweak changes ordering. The `contextScore` comparison prevents a fixture from passing while proving only deterministic ordering.

- [ ] **Step 4: Run contextual fixture test**

Run:

```bash
composer run test:php -- --filter RecommendationEvaluationTest
```

Expected: PASS.

## Task 8: Update Surface-Specific Parser Tests

**Files:**

- Modify: `tests/phpunit/PromptRulesTest.php`
- Modify: `tests/phpunit/StylePromptTest.php`
- Modify: `tests/phpunit/TemplatePromptTest.php`
- Modify: `tests/phpunit/TemplatePartPromptTest.php`
- Modify: `tests/phpunit/NavigationAbilitiesTest.php`
- Modify: `tests/phpunit/BlockAbilitiesTest.php`
- Modify: `tests/phpunit/StyleAbilitiesTest.php`
- Modify: `tests/phpunit/TemplateAbilitiesTest.php`

- [ ] **Step 1: Add assertions for ranking component fields**

For each surface parser test that already asserts `ranking.score`, add assertions like:

```php
$ranking = $payload['suggestions'][0]['ranking'];

$this->assertSame( 'contextual-ranking-v1', $ranking['rankingVersion'] );
$this->assertArrayHasKey( 'modelScore', $ranking );
$this->assertArrayHasKey( 'deterministicScore', $ranking );
$this->assertArrayHasKey( 'contextScore', $ranking );
$this->assertArrayHasKey( 'blendedScore', $ranking );
$this->assertIsArray( $ranking['contextEvidence'] );
$this->assertIsArray( $ranking['contextPenalties'] );
$this->assertSame( $ranking['score'], $ranking['blendedScore'] );
```

Use the correct list path for block lanes:

- `$payload['settings'][0]['ranking']`
- `$payload['styles'][0]['ranking']`
- `$payload['block'][0]['ranking']`

- [ ] **Step 2: Update exact score assertions**

Where tests assert exact score values from model/deterministic-only blending, update them to the new scores produced by the context component. Keep exact assertions only when the fixture context is stable. Use `assertGreaterThan()` for ranking-order intent when exact score precision would obscure the behavior under test.

- [ ] **Step 3: Run parser-family tests**

Run:

```bash
composer run test:php -- --filter 'PromptRulesTest|StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest|RecommendationContextScorerTest|RankingContractTest'
```

Expected: PASS.

## Task 9: Verify No Model-Facing Schema Drift

**Files:**

- No planned production file changes.
- Modify: `tests/phpunit/ResponseSchemaTest.php`.

- [ ] **Step 1: Add negative assertions for plugin-generated component fields**

In `tests/phpunit/ResponseSchemaTest.php`, import `FlavorAgent\Support\RankingContract` and add a helper:

```php
private function assert_no_contextual_component_fields_in_llm_ranking_schema( array $ranking, string $message ): void {
	foreach ( RankingContract::PLUGIN_COMPONENT_KEYS as $plugin_generated_field ) {
		$this->assertArrayNotHasKey(
			$plugin_generated_field,
			$ranking['properties'] ?? [],
			"{$message} must not expose plugin-generated contextual ranking field {$plugin_generated_field} to the model."
		);
	}
}
```

Call it from `test_strict_llm_schemas_accept_nullable_ranking_objects()` after the existing required-key assertions:

```php
$this->assert_no_contextual_component_fields_in_llm_ranking_schema( $ranking, $surface );
```

Call it from `test_block_schema_accepts_nullable_ranking_objects_on_all_lanes()` for each block lane:

```php
$this->assert_no_contextual_component_fields_in_llm_ranking_schema( $ranking, "block {$list_key}" );
```

These negative assertions must be in `ResponseSchemaTest`, not only in a search command, so accidental optional schema drift fails in CI.

- [ ] **Step 2: Run response schema tests**

Run:

```bash
composer run test:php -- --filter ResponseSchemaTest
```

Expected: PASS. The strict LLM `ranking` object should still require only `score`, `reason`, `sourceSignals`, `designPrinciple`, and `risk`, with nullable object support. Do not add `modelScore`, `contextScore`, `contextEvidence`, or `rankingVersion` to `ResponseSchema`.

- [ ] **Step 3: Search for parser null context**

Run:

```bash
rg -n "'context'\\s*=>\\s*null|\\\"context\\\"\\s*=>\\s*null|context\\s*=>\\s*null" inc/LLM inc/Abilities
```

Expected: no matches in parser/ability production code. Matches in tests that intentionally exercise missing context are acceptable only when the filename is under `tests/` and the test name states the null-context fallback.

## Task 10: Documentation and Aggregate Verification

**Files:**

- Modify: `docs/reference/activity-state-machine.md`

- [ ] **Step 1: Run docs check**

Run:

```bash
npm run check:docs
```

Expected: PASS.

- [ ] **Step 2: Run cross-surface PHP suite**

Run:

```bash
composer run test:php -- --filter 'RecommendationContextScorerTest|RankingContractTest|RecommendationEvaluationTest|ResponseSchemaTest|RegistrationTest|RecommendationOutcomeTest|PromptRulesTest|StylePromptTest|TemplatePromptTest|TemplatePartPromptTest|NavigationAbilitiesTest|BlockAbilitiesTest|StyleAbilitiesTest|TemplateAbilitiesTest'
```

Expected: PASS.

- [ ] **Step 3: Run targeted JS outcome and apply/undo tests**

Run:

```bash
npm run test:unit -- recommendation-outcomes store-actions
```

Expected: PASS. `recommendation-outcomes` covers `ranking`/`rankingSet` diagnostics. `store-actions` protects existing apply/undo behavior while ranking metadata is added to recommendation identities.

- [ ] **Step 4: Run aggregate verifier without browser harnesses**

Run:

```bash
node scripts/verify.js --skip-e2e
```

Expected: PASS or an explicit known-red/incomplete state unrelated to Contextual Ranking V1. If incomplete, inspect `output/verify/summary.json` and record the exact blocker.

- [ ] **Step 5: Run Playground browser harness**

Run:

```bash
npm run test:e2e:playground
```

Expected: PASS for post editor, block Inspector, pattern inserter, and navigation flows. If this harness is unavailable or known-red, record the exact command output, environment blocker, and waiver decision before sign-off.

- [ ] **Step 6: Run WP 7.0 browser harness**

Run:

```bash
npm run test:e2e:wp70
```

Expected: PASS for Site Editor template, template-part, Global Styles, Style Book, and refresh/drift-sensitive flows. If this harness is unavailable or known-red, record the exact command output, environment blocker, and waiver decision before sign-off.

- [ ] **Step 7: Run diff hygiene**

Run:

```bash
git diff --check
```

Expected: no whitespace errors.

## Success Criteria

Contextual Ranking V1 is done when:

1. Block, style, template, template-part, and navigation parser families pass non-null context scores into `RankingContract::blend_score()`.
2. Ability-level tests prove block, style, template, template-part, and navigation recommendation methods pass prompt/docs/execution ranking context into parser/rerank seams.
3. Context scoring uses bounded, explainable evidence, fixed V1 penalty values, a capped aggregate penalty, and explicit support-fit precedence.
4. Block, style, template, template-part, navigation, and dedicated pattern recommendation items include plugin-generated `modelScore`, `deterministicScore`, `contextScore`, `blendedScore`, `contextEvidence`, `contextPenalties`, and `rankingVersion: contextual-ranking-v1` where that surface has enough context to score. Pattern recommendations also emit a `contextual_ranking_v1` source signal. Spoof protection still applies — model/provider-supplied contextual component fields must not survive `RankingContract::normalize()` unless they came from plugin-generated defaults.
5. Model/provider-supplied ranking metadata cannot override or create plugin-generated component fields.
6. Local outcome diagnostics can carry compact per-suggestion ranking snapshots and bounded aggregate `shown` ranking sets without raw prompt text, generated recommendation text, prose-derived `suggestionKey` values, post content, block attributes, validation messages, pattern payloads, remote telemetry, or silent training.
7. Fixture-backed parser/scorer tests prove predictable ordering improvements:
   - prompt-matched style suggestions outrank generic suggestions,
   - supported operations outrank unsupported/rejected operations,
   - native preset suggestions outrank arbitrary custom values when presets exist,
   - fresh docs-grounded scorer contexts outrank stale docs-grounded scorer contexts,
   - section-role-matched pattern suggestions emitted through parser surfaces outrank mismatched pattern suggestions,
   - likely no-ops are demoted,
   - validation-risky recommendations are demoted or blocked.
8. Block contextual ranking fixtures exercise parse, block-context enforcement, and final rerank before asserting top order.
9. Existing provider-facing `ResponseSchema` ranking contracts remain stable, with explicit negative tests proving contextual component fields are absent.
10. `npm run check:docs`, focused PHP/JS tests, `node scripts/verify.js --skip-e2e`, `npm run test:e2e:playground`, `npm run test:e2e:wp70`, and `git diff --check` have recorded outcomes, or the browser harnesses have explicit recorded blockers/waivers.
11. Missing optional context produces neutral evidence, not ranking collapse, positive boosts, or unsupported penalties.
12. Aggregate `shown` diagnostics store a bounded result-set `rankingSet`, while per-suggestion outcomes store a single compact `ranking` snapshot.
13. Contextual ranking changes are measurable through fixtures without provider calls or external telemetry, and fixture assertions include component snapshots plus a `0.01` blended-score margin over the runner-up.
14. Block support-fit tests prove live execution-contract dot strings such as `color.background` match block style update paths before the scorer assigns support penalties, and prove explicit path inventory wins over broad `allowedPanels`.
15. Block contextual-ranking fixtures that assert accepted structural operations declare and fixture-scope the Block Structural Actions precondition instead of relying on the production default.
16. `contextEvidence` and `contextPenalties` are numeric-only bounded maps from plugin defaults, source signals do not duplicate `contextual_ranking_v1`, and final block rerank works with legacy payloads that only have `ranking.score`.

## Stop Line

Stop after Contextual Ranking V1 is implemented and verified. Do not add learning loops, weight tuning from outcomes, new remote telemetry, silent prompt mutation, or full design diagnosis in this phase.
