---
description: "Review whether a recommendation-quality roadmap phase from docs/reference/current-open-work.md is actually shipped (code + tests + docs + metrics) and whether recent changes regressed it"
name: "Review Roadmap Phase Completion"
argument-hint: "Phase number/name, a commit or PR to review, or 'current working tree'"
agent: "agent"
---

Review a Flavor Agent recommendation-quality roadmap phase for true completion and regression safety, using `docs/reference/current-open-work.md` as the specification of record.

Use the invocation arguments to scope the review: a phase number/name (e.g. "Phase 6" or "Pattern Metadata"), a commit/PR to assess, or "current working tree" for uncommitted work. If the arguments are missing or too vague, ask one concise clarifying question before reading widely.

## Review workflow

Work in two passes so the review stays tight and auditable. Do **not** edit any file until findings are presented and the user asks for changes.

1. **Map first, then stop.** From `docs/reference/current-open-work.md` and the seams below, list the 5–8 files/tests/docs that actually govern the phase under review. State that short map and the acceptance criteria you are checking against before opening implementation details.
2. **Inspect only the mapped surface.** Confirm each acceptance criterion against code, tests, and docs — in that order — rather than re-deriving the architecture with broad searches.
3. **Assess completion honestly.** Score the matching `docs/reference/current-open-work.md` table row as **verified**, **partial/drifted**, or **overstated** with `file:line` evidence. Treat a still-open row that is in fact implemented as drift too.
4. **Separate the two finding classes.** Keep **blocking regressions / correctness bugs** distinct from **roadmap-wording or doc-accuracy issues**. Never silently fold one into the other.
5. **Report, then wait.** Present findings and a minimal fix plan before touching files.

## Roadmap source of truth

- `docs/reference/current-open-work.md` — the live queue for remaining ranking/learning follow-ups (fixture harvest, bounded ranking feedback, editable site preference summaries). Reconcile shipped-versus-open against `STATUS.md` and the current source tree, not against retired plans.
- Shipped baseline: fixture-backed evaluation, contextual ranking, validation-reasons vocabulary, docs fingerprint split, learning-attribution join, and the bounded admin learning report. Remaining later-phase work is sequenced in the current-open-work table. Verify this against `STATUS.md` and `git log` rather than assuming a dated prompt is current.

## Files and seams to inspect first

Pick the subset that matches the phase; do not open all of these every time.

- **Metrics / evaluation harness:** `tests/phpunit/RecommendationEvaluationTest.php`, `tests/phpunit/fixtures/recommendation-evaluation-*`, `tests/phpunit/PromptBudgetTest.php`
- **Ranking:** `inc/Support/RankingContract.php`, `inc/Support/RecommendationContextScorer.php`, and the per-surface parsers in `inc/LLM/Prompt.php`, `StylePrompt.php`, `TemplatePrompt.php`, `TemplatePartPrompt.php`, `NavigationPrompt.php`
- **Strict schemas:** `inc/LLM/ResponseSchema.php`, proven by `tests/phpunit/ResponseSchemaTest.php`
- **Design semantics:** `inc/Support/DesignSemantics.php`, `src/context/collector.js`, `src/utils/recommendation-design-semantics.js`
- **Freshness signatures:** `inc/Support/RecommendationResolvedSignature.php`, `inc/Support/RecommendationReviewSignature.php`, `src/utils/block-recommendation-context.js`, `src/templates/template-recommender-helpers.js`, `src/template-parts/template-part-recommender-helpers.js`, `src/utils/style-operations.js`
- **Validation feedback / diagnostics:** `inc/Abilities/RecommendationAbilityExecution.php`, `inc/Abilities/{Block,Style,Template}Abilities.php`, `src/store/recommendation-outcomes.js`
- **Guideline attribution:** `Guidelines::version_id()` / `Guidelines::format_prompt_context()`, `inc/Activity/Repository.php`, `inc/Activity/Serializer.php`
- **Docs fingerprint:** `inc/Support/DocsGuidanceResult.php`
- **Pattern metadata/ranking:** `inc/Patterns/PatternIndex.php`, `inc/Patterns/Retrieval/QdrantPatternRetrievalBackend.php`, `inc/Patterns/Retrieval/CloudflareAISearchPatternRetrievalBackend.php`, `inc/Abilities/PatternAbilities.php`
- **Learning loop:** `inc/Activity/RecommendationOutcome.php`, `inc/Activity/RecommendationOutcomeMetrics.php`, `inc/REST/Agent_Controller.php`, `src/store/activity-undo.js`, `src/admin/activity-log.js`, `src/admin/activity-log-utils.js`
- **Cross-cutting contracts/docs:** `docs/SOURCE_OF_TRUTH.md`, `docs/FEATURE_SURFACE_MATRIX.md`, `docs/reference/cross-surface-validation-gates.md`, `docs/reference/abilities-and-routes.md`, `docs/reference/wordpress-ai-roadmap-tracking.md`, `STATUS.md`

## Phase completion checklist

A phase is "done" only when all four hold — flag any that is missing:

- **Code:** the seam exists and matches the proposed shape/score blend, not just a stub.
- **Tests:** the phase's named PHP/JS suites assert the new contract, and prior `derive_score()`-style tests became component coverage rather than dead fallback tests.
- **Docs:** `docs/reference/current-open-work.md`, `STATUS.md`, and any operator-facing contract docs reflect the actual behavior; `npm run check:docs` is clean.
- **Metrics gate:** the phase's stated `RecommendationEvaluationTest` movement/preservation target (`invalidOperationRate`, `presetAdherenceRate`, `noOpRate`, `noiseRate`, or an expanded metric) is checked **in the same run**. Per the Risk Controls, do not accept a "metrics gate passed" claim that did not run `RecommendationEvaluationTest`.

Standing safety rules: no guideline-id-as-freshness, no model ranking overriding validators, no `PromptBudget` bypass, no raw provider payloads/full block trees in diagnostics, no hashing volatile labels into applicability signatures, no widening patterns into apply/undo.

## Validation commands

Prefer the nearest targeted PHPUnit/JS suites named by the workstream in `docs/reference/current-open-work.md`. The general fast loop, mirroring `docs/reference/cross-surface-validation-gates.md`:

- `composer run test:php -- --filter '<nearest Test class>'` (always include `RecommendationEvaluationTest` when a metrics gate is claimed)
- `npm run test:unit -- --runInBand <nearest JS suites for the phase>`
- `node scripts/verify.js --skip-e2e` then inspect `output/verify/summary.json` for shared ranking/schema/provider/backend changes
- `npm run check:docs` when contracts, surfacing rules, or roadmap status change
- `git diff --check`

Run matching Playwright harnesses (`playground` for post-editor/block/pattern/navigation, `wp70` for Site Editor/template/Global Styles/Style Book) only for user-visible regressions; if a harness is known-red or unavailable, record the blocker or an explicit waiver instead of skipping silently.

## Expected output

Return a concise review with this structure:

1. **Scope** — the phase/commit reviewed and the acceptance criteria checked.
2. **Completion verdict** — per criterion: verified / partial-drifted / overstated, with `file:line` evidence.
3. **Blocking regressions** — correctness or safety bugs that must be fixed (each with file, cause, and fix sketch). State "none found" explicitly if so.
4. **Roadmap-wording / doc drift** — places where `docs/reference/current-open-work.md`, `STATUS.md`, or contract docs misstate the tree, kept separate from regressions.
5. **Metrics gate status** — which metrics were actually exercised and their movement, or why the gate is unproven.
6. **Fix plan** — the smallest safe change set, tests/docs to update, and the validation commands to run.

If code changes are requested after findings are presented, implement them incrementally, update the nearest tests and the current-open-work/status docs together, and report the verification results. Do not close a current-open-work row without the code, test, doc, and metrics evidence that the checklist requires.
