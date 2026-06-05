---
description: "Review whether an improving-levers.md roadmap phase is actually shipped (code + tests + docs + metrics) and whether recent changes regressed it"
name: "Review Roadmap Phase Completion"
argument-hint: "Phase number/name, a commit or PR to review, or 'current working tree'"
agent: "agent"
---

Review a Flavor Agent recommendation-quality roadmap phase for true completion and regression safety, using `improving-levers.md` as the specification of record.

Use the invocation arguments to scope the review: a phase number/name (e.g. "Phase 6" or "Pattern Metadata"), a commit/PR to assess, or "current working tree" for uncommitted work. If the arguments are missing or too vague, ask one concise clarifying question before reading widely.

## Review workflow

Work in two passes so the review stays tight and auditable. Do **not** edit any file until findings are presented and the user asks for changes.

1. **Map first, then stop.** From `improving-levers.md` and the seams below, list the 5–8 files/tests/docs that actually govern the phase under review. State that short map and the acceptance criteria you are checking against before opening implementation details.
2. **Inspect only the mapped surface.** Confirm each acceptance criterion against code, tests, and docs — in that order — rather than re-deriving the architecture with broad searches.
3. **Assess completion honestly.** For each acceptance-criteria box the roadmap marks `[x]`, classify it as **verified**, **partial/drifted**, or **overstated** with `file:line` evidence. Treat an unchecked `[ ]` that is in fact implemented as drift too.
4. **Separate the two finding classes.** Keep **blocking regressions / correctness bugs** distinct from **roadmap-wording or doc-accuracy issues**. Never silently fold one into the other.
5. **Report, then wait.** Present findings and a minimal fix plan before touching files.

## Roadmap source of truth

- `improving-levers.md` — the phase list, per-phase acceptance criteria, per-phase verification commands, Risk Controls, and the "Suggested Implementation Order" status note are authoritative. Re-read the status note: it records which phases are shipped versus unshipped and must be reconciled with the tree, not trusted blindly.
- Shipped baseline as last recorded: Phases 0/1/2 + Contextual Ranking V1; Phase 3 via #29 (`c2a22f5`, design in `docs/superpowers/specs/2026-06-04-phase-3-validation-feedback-design.md`); Phase 4's request-diagnostic attribution seam (one future learning-loop box still open). Phases 5–12 unshipped. Verify this against `STATUS.md` and `git log` rather than assuming it is current.
- Archived plans/specs live under `docs/reference/archive/` and `docs/superpowers/plans/archive/`.

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
- **Docs:** `improving-levers.md`, `STATUS.md`, and any operator-facing contract docs reflect the actual behavior; `npm run check:docs` is clean.
- **Metrics gate:** the phase's stated `RecommendationEvaluationTest` movement/preservation target (`invalidOperationRate`, `presetAdherenceRate`, `noOpRate`, `noiseRate`, or an expanded metric) is checked **in the same run**. Per the Risk Controls, do not accept a "metrics gate passed" claim that did not run `RecommendationEvaluationTest`.

Cross-check the Risk Controls section every time: no guideline-id-as-freshness, no model ranking overriding validators, no `PromptBudget` bypass, no raw provider payloads/full block trees in diagnostics, no hashing volatile labels into applicability signatures, no widening patterns into apply/undo.

## Validation commands

Prefer the exact per-phase **Verification** block in `improving-levers.md` for the phase under review. The general fast loop, mirroring `docs/reference/cross-surface-validation-gates.md`:

- `composer run test:php -- --filter '<phase filter from improving-levers.md>'` (always include `RecommendationEvaluationTest` when a metrics gate is claimed)
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
4. **Roadmap-wording / doc drift** — places where `improving-levers.md`, `STATUS.md`, or contract docs misstate the tree, kept separate from regressions.
5. **Metrics gate status** — which metrics were actually exercised and their movement, or why the gate is unproven.
6. **Fix plan** — the smallest safe change set, tests/docs to update, and the validation commands to run.

If code changes are requested after findings are presented, implement them incrementally, update the nearest tests and the roadmap/status docs together, and report the verification results. Do not mark a roadmap box `[x]` without the code, test, doc, and metrics evidence that the checklist requires.
