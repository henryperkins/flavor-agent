# Governance Learning Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Phase 9 read-only governance learning report in `Settings > AI Activity`, backed by bounded, sanitized local activity data.

**Architecture:** Keep the existing admin activity route and permission boundary. Add a dedicated `FlavorAgent\Activity\GovernanceLearningReport` builder that consumes a bounded hydrated sample and reuses `RecommendationOutcomeMetrics::evaluate()` for existing outcome-rate denominators. Preserve the existing summary-card SQL/projected path and append `learningReport` from a shared `Repository::query_admin()` step so projected and fallback admin paths behave the same.

**Tech Stack:** WordPress plugin PHP 8.2, PHPUnit, REST API route args, React via `@wordpress/element`, `@wordpress/components`, DataViews, Jest via `@wordpress/scripts`, repo CSS tokens.

---

## File Map

- Create `inc/Activity/GovernanceLearningReport.php`: report builder, group aggregation, sanitization, labels, bounded metadata.
- Modify `inc/Activity/RecommendationOutcome.php`: sanitize and persist `patternTraits` on shown `rankingSet` items and engaged pattern outcome rows.
- Modify `inc/Activity/Repository.php`: hydrate a newest-first bounded report sample under current admin filters and append `learningReport` only when requested.
- Modify `inc/REST/Agent_Controller.php`: register/pass `includeReports` for global admin reads; scoped reads ignore it.
- Modify `src/store/recommendation-outcomes.js`: normalize `traits` / `patternTraits` from pattern suggestions into `patternTraits`.
- Modify `src/patterns/PatternRecommender.js`: pass engaged pattern traits into outcome entries.
- Modify `src/admin/activity-log-utils.js`: normalize malformed/missing/truncated learning report payloads for display.
- Modify `src/admin/activity-log.js`: request `includeReports=1`, store report data, render compact report section under summary cards.
- Modify `src/admin/activity-log.css`: add un-nested report layout styles reusing existing activity-log tokens.
- Modify tests in `tests/phpunit/*` and `src/**/__tests__/*` named below.
- Update docs: `docs/features/activity-and-audit.md`, `docs/reference/governance-layer.md`, `improving-levers.md`, `docs/reference/current-open-work.md`, `STATUS.md`, `docs/SOURCE_OF_TRUTH.md`.

## Implementation Rules

- `shown` remains exposure only.
- Report output contains metadata, counts, rates, labels, and representative activity ids only.
- The report sample is newest-first, bounded to default `500`, capped at `1000`, independent from page pagination.
- Existing summary cards remain full filtered status counts; report rates are bounded-sample outcome metrics.
- Pattern trait shown counts are top-ranked exposure because ranking sets are capped at three.
- No raw prompt, provider payload, generated text, full pattern content, content preview, or block tree enters the report payload.
- Use TDD for every production change: write failing test, run failing command, implement minimal code, rerun.

---

### Task 1: Pattern Trait Persistence

**Files:**
- Modify: `inc/Activity/RecommendationOutcome.php`
- Modify: `src/store/recommendation-outcomes.js`
- Modify: `src/patterns/PatternRecommender.js`
- Test: `tests/phpunit/RecommendationOutcomeTest.php`
- Test: `src/store/__tests__/recommendation-outcomes.test.js`
- Test: `src/patterns/__tests__/PatternRecommender.test.js`

- [x] **Step 1: Add failing PHP trait-normalization tests**

Add tests that prove `RecommendationOutcome::normalize_entry()` keeps only sanitized slugs, dedupes, caps arrays, preserves `patternTraits` for shown ranking-set items, preserves `patternTraits` for engaged pattern outcomes, and rejects raw content-shaped keys.

Run:
```bash
composer run test:php -- --filter 'RecommendationOutcomeTest'
```
Expected: FAIL because `patternTraits` is not persisted yet.

- [x] **Step 2: Implement minimal PHP trait normalization**

Add a private `normalize_pattern_traits()` helper in `RecommendationOutcome`, call it for shown `rankingSet` items and for non-`shown` outcome rows, and mirror accepted traits into `request.recommendation`.

- [x] **Step 3: Verify PHP trait persistence**

Run:
```bash
composer run test:php -- --filter 'RecommendationOutcomeTest'
```
Expected: PASS.

- [x] **Step 4: Add failing JS outcome-builder tests**

Add tests proving `getRecommendationOutcomeSummaryFromPayload()` includes `patternTraits` on shown ranking-set items from `traits` / `patternTraits`, and `buildRecommendationOutcomeEntry()` includes engaged `patternTraits` while omitting raw content fields.

Run:
```bash
npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js
```
Expected: FAIL because JS builders do not emit `patternTraits`.

- [x] **Step 5: Implement minimal JS trait builders**

Add a small sanitizer in `recommendation-outcomes.js`, include sanitized traits in `buildRankingSetFromSuggestions()`, `buildRecommendationIdentityFromSuggestion()`, and `buildRecommendationOutcomeEntry()`. Update `PatternRecommender` so engaged pattern outcomes pass the selected recommendation/pattern traits.

- [x] **Step 6: Verify JS trait persistence**

Run:
```bash
npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js src/patterns/__tests__/PatternRecommender.test.js
```
Expected: PASS.

---

### Task 2: PHP Report Builder

**Files:**
- Create: `inc/Activity/GovernanceLearningReport.php`
- Test: `tests/phpunit/GovernanceLearningReportTest.php`
- Test: `tests/phpunit/RecommendationOutcomeEvaluationTest.php`

- [x] **Step 1: Add failing builder tests**

Create `GovernanceLearningReportTest` covering summary metadata, denominator parity with `RecommendationOutcomeMetrics::evaluate()`, undo rate, insert-failed rate, groups for surfaces, operation types, provider/model, validation reasons, guideline versions, ranking signals, pattern traits, representative ids, malformed-row tolerance, and top-ranked trait exposure.

Run:
```bash
composer run test:php -- --filter 'GovernanceLearningReportTest|RecommendationOutcomeEvaluationTest'
```
Expected: FAIL because the class does not exist.

- [x] **Step 2: Implement `GovernanceLearningReport`**

Implement `build( array $entries, array $args = [] ): array` with version `governance-learning-report-v1`, `generatedAt`, `sampleSize`, `rowLimit`, `truncated`, a `summary` block, and the seven group arrays. Use `RecommendationOutcomeMetrics::evaluate()` for `shownCount`, `reviewSelectionRate`, `applyConversionRate`, and `validationBlockedRate`.

- [x] **Step 3: Verify builder tests**

Run:
```bash
composer run test:php -- --filter 'GovernanceLearningReportTest|RecommendationOutcomeEvaluationTest'
```
Expected: PASS.

---

### Task 3: Repository And REST Contract

**Files:**
- Modify: `inc/Activity/Repository.php`
- Modify: `inc/REST/Agent_Controller.php`
- Test: `tests/phpunit/ActivityRepositoryTest.php`
- Test: `tests/phpunit/AgentControllerTest.php`

- [x] **Step 1: Add failing repository tests**

Add tests that prove `Repository::query_admin()` omits `learningReport` by default, includes it only for `includeReports`, respects row limit/truncation, does not disturb pagination/summary counts, and returns the same report through projected and fallback paths.

Run:
```bash
composer run test:php -- --filter 'ActivityRepositoryTest'
```
Expected: FAIL because `includeReports` is ignored.

- [x] **Step 2: Implement bounded sample hydration**

Add repository helpers to query up to `rowLimit + 1` matching admin rows newest-first using the same filter context, hydrate full entries, resolve statuses, trim to `rowLimit`, and call `GovernanceLearningReport::build()`. Append `learningReport` after either admin path has built its normal response.

- [x] **Step 3: Add failing REST tests**

Add tests that prove global `includeReports=1` returns `learningReport` for `manage_options`, global default omits it, and scoped reads ignore `includeReports`.

Run:
```bash
composer run test:php -- --filter 'AgentControllerTest'
```
Expected: FAIL until the REST arg is registered and passed.

- [x] **Step 4: Implement REST arg wiring**

Register `includeReports` as a boolean arg on `/flavor-agent/v1/activity`, pass it only into the global admin `query_admin()` call, and leave scoped reads unchanged.

- [x] **Step 5: Verify PHP REST/repository contract**

Run:
```bash
composer run test:php -- --filter 'GovernanceLearningReportTest|ActivityRepositoryTest|AgentControllerTest|RecommendationOutcomeTest|RecommendationOutcomeEvaluationTest'
```
Expected: PASS.

---

### Task 4: Admin UI Report Section

**Files:**
- Modify: `src/admin/activity-log-utils.js`
- Modify: `src/admin/activity-log.js`
- Modify: `src/admin/activity-log.css`
- Test: `src/admin/__tests__/activity-log-utils.test.js`
- Test: `src/admin/__tests__/activity-log.test.js`

- [x] **Step 1: Add failing report-normalization tests**

Add tests for a `normalizeGovernanceLearningReport()` helper: missing/malformed returns `null`, valid reports are shaped for rendering, groups are bounded arrays, rates are numeric, and truncated metadata survives.

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js
```
Expected: FAIL because the helper does not exist.

- [x] **Step 2: Implement report normalization helper**

Export `normalizeGovernanceLearningReport()` from `activity-log-utils.js`.

- [x] **Step 3: Add failing admin UI tests**

Update activity log tests to expect `includeReports=1` in the request URL, response `learningReport` state retention, report metadata rendering, group rows with representative links, and no rendered report when payload is malformed.

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log.test.js
```
Expected: FAIL because the UI does not request or render the report.

- [x] **Step 4: Implement UI rendering and CSS**

Add `learningReport` to response state, append `includeReports=1` in `getActivityRequestUrl()`, render `GovernanceLearningReportSection` under the summary grid, and add CSS classes under `.flavor-agent-activity-log__learning-report*` without nesting cards inside cards.

- [x] **Step 5: Verify admin UI**

Run:
```bash
npm run test:unit -- --runInBand src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js
```
Expected: PASS.

---

### Task 5: Documentation And Queue Closeout

**Files:**
- Modify: `docs/features/activity-and-audit.md`
- Modify: `docs/reference/governance-layer.md`
- Modify: `improving-levers.md`
- Modify: `docs/reference/current-open-work.md`
- Modify: `STATUS.md`
- Modify: `docs/SOURCE_OF_TRUTH.md`

- [x] **Step 1: Update docs after code passes**

Document the report as a read-only, bounded aggregate evidence layer. Keep fixture harvest, ranking feedback, editable preference summaries, richer diffs, notifications, and editor-side pending visibility open.

- [x] **Step 2: Verify docs**

Run:
```bash
npm run check:docs
```
Expected: PASS.

---

### Task 6: Final Verification

**Files:**
- All changed files.

- [x] **Step 1: Run targeted PHP**

```bash
composer run test:php -- --filter 'RecommendationOutcomeMetrics|GovernanceLearningReport|ActivityRepository|AgentControllerTest|RecommendationOutcomeTest'
```

- [x] **Step 2: Run targeted JS**

```bash
npm run test:unit -- --runInBand src/store/__tests__/recommendation-outcomes.test.js src/patterns/__tests__/PatternRecommender.test.js src/admin/__tests__/activity-log-utils.test.js src/admin/__tests__/activity-log.test.js
```

- [x] **Step 3: Run docs and whitespace checks**

```bash
npm run check:docs
git diff --check
```

- [x] **Step 4: Run aggregate non-E2E verification**

```bash
npm run verify -- --skip-e2e
```

- [x] **Step 5: Review diff and status**

```bash
git status --short
git diff --stat
```

Confirm the implementation stays inside the report slice and does not claim Phase 10+ learning work.
