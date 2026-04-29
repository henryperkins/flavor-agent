# Recommendation Improvement Report And Checklist

Canonical scoped implementation plan: `docs/reference/recommendation-actionability-implementation-plan.md`.

Use that plan when scope conflicts with this broader backlog checklist. This document remains the expanded checklist and product-direction backlog.

## Report

The best improvement path is to keep the current surface model, then let the LLM propose richer recommendations while promoting only the subset that can be reduced to validated, allow-listed, live-state-checked operations.

Current model is sound:

- Block recommendations: direct apply is appropriate only for safe local block attribute changes; broader structural or pattern ideas should remain advisory unless they enter a review/apply/undo flow. See `docs/features/block-recommendations.md` and the block prompt guardrails in `inc/LLM/Prompt.php`.
- Pattern recommendations: the standalone inserter shelf should stay ranking/browse-only, scoped to `visiblePatternNames`, with insertion handled through core Gutenberg. See `docs/features/pattern-recommendations.md`.
- Style recommendations: Global Styles and Style Book should remain review-first, limited to validated `theme.json` paths and registered variations. See `docs/features/style-and-theme-intelligence.md` and `inc/LLM/StylePrompt.php`.

### Highest-Value Improvements

1. Add a shared eligibility classifier.

   Every recommendation should expose why it is `inline-safe`, `review-safe`, or `advisory`, plus any blockers. The UI already behaves this way, but a machine-readable eligibility contract would improve debugging, ranking, audit records, and future ability consumers.

2. Promote narrow block pattern actions into review-first operations.

   Keep one-click block apply for local attributes only. Add a separate review-safe path for:

   - insert an allowed pattern before or after the selected block
   - replace the selected block with an allowed pattern

   Required prerequisites: pass allowed/visible pattern context into block requests, extend the block response schema, add block-keyed review state, validate against the live editor tree immediately before mutation, and record undoable activity. Do not reuse template-part helpers directly; extract a shared structural operation core first. This aligns with the existing review in `recommendation-actionability-review.md`.

3. Improve pattern ranking explainability.

   The backend already scopes by visible patterns and uses semantic plus structural retrieval in `inc/Abilities/PatternAbilities.php`. The next improvement is surfacing better "why this pattern" evidence: source signals, matched categories, nearby-block fit, override/binding relevance, and empty-result diagnostics. Keep the badge tied only to renderable recommendations, as it is now in `src/patterns/InserterBadge.js`.

4. Improve style recommendation quality with deterministic evaluators.

   Style validation already restricts operations to supported paths and preset-backed values in `inc/LLM/StylePrompt.php`. Add a deterministic contrast/readability pass before returning executable color suggestions, and prefer paired foreground/background operations when one would create poor contrast. Next, expand supported operations only where core exposes stable paths: button pseudo-states, block element selectors, and native style variation previews.

5. Keep upstream boundaries explicit.

   Avoid building a parallel site agent, provider router, or long-term observability system. The local roadmap notes active upstream pressure around Site Agent, Observability Logger, ability annotations, and provider routing in `docs/reference/wordpress-ai-roadmap-tracking.md`. New apply-capable work should also add accurate ability annotations when that contract changes.

### Suggested Sequence

1. Add shared eligibility metadata and diagnostics.
2. Improve pattern/style explanation surfaces without changing apply contracts.
3. Add the narrow block pattern review-safe operation path.
4. Add contrast and native-preview improvements for styles.
5. Run the cross-surface gates in `docs/reference/cross-surface-validation-gates.md`.

Latest saved verification already records a full pass in `output/verify/summary.json`, but no new test pass was run for this report.

## Actionable Checklist

### Phase 0: Lock Scope

- [ ] Keep Flavor Agent editor-bound; do not add a site agent, provider router, or new observability product.
- [ ] Preserve current modes:
  - Block: inline apply only for safe local attributes.
  - Pattern: inserter ranking/browse-only.
  - Styles: review-before-apply for validated `theme.json` operations.
- [ ] Treat all LLM output as proposals, never authorization.

### Phase 1: Add Recommendation Eligibility Metadata

- [ ] Define shared eligibility states: `inline-safe`, `review-safe`, `advisory`.
- [ ] Add blocker reasons, e.g. `unsupported_path`, `missing_visible_pattern_scope`, `content_only`, `locked_block`, `stale_context`.
- [ ] Add computed eligibility metadata to normalized API/client payloads after schema parsing and deterministic validation.
- [ ] Treat LLM-provided eligibility as advisory/debug text only; it must not authorize execution.
- [ ] Store both proposed operations and validated executable operations.
- [ ] Preserve advisory remainder when only part of a recommendation is executable.
- [ ] Surface eligibility in UI cards, activity diagnostics, and tests.
- [ ] Update parser/normalizer tests for each touched prompt class.

Suggested client shape:

```ts
type RecommendationEligibility = {
  tier: 'inline-safe' | 'review-safe' | 'advisory';
  source: 'validator';
  blockers: EligibilityBlocker[];
  executableOperations: NormalizedOperation[];
  advisoryOperationsRejected?: RejectedOperation[];
};

type NormalizedRecommendation = {
  id: string;
  title: string;
  rationale: string;
  proposedOperations: ProposedOperation[];
  executableOperations: ValidatedOperation[];
  eligibility: RecommendationEligibility;
  advisoryRemainder: string[];
};
```

### Phase 1A: Define Operation And Eligibility Contracts

- [ ] Define a versioned operation catalog before changing prompts.
- [ ] Treat LLM operations as proposed operations only.
- [ ] Compute final eligibility in deterministic validator code.
- [ ] Store both proposed operations and validated executable operations.
- [ ] Preserve advisory remainder when only part of a recommendation is executable.
- [ ] Add parser fixtures for:
  - [ ] valid operations
  - [ ] unknown operation types
  - [ ] unknown pattern names
  - [ ] stale targets
  - [ ] locked targets
  - [ ] unsupported paths
  - [ ] mixed executable/advisory recommendations

### Phase 2: Improve Pattern Recommendations

- [ ] Add richer ranking explanations: matched category, insertion context fit, nearby block fit, override/binding relevance.
- [ ] Add empty-result diagnostics for missing scope, no visible allowed patterns, index unavailable, or all candidates filtered.
- [ ] Keep `visiblePatternNames` as the hard insertion-scope contract.
- [ ] Keep synced/user pattern read checks before ranking.
- [ ] Keep badge counts based only on renderable allowed-pattern matches.
- [ ] Do not attach Flavor Agent activity/undo ownership to ordinary native pattern inserter use.

### Phase 3: Add Narrow Block Pattern Actions

- [ ] Define a concrete rollout flag source for block structural actions:
  - [ ] site option, environment constant, or plugin setting
  - [ ] localized JS value
  - [ ] REST/Abilities behavior when disabled
  - [ ] fallback behavior for recommendations generated while disabled
- [ ] When disabled, structural recommendations must normalize to advisory.
- [ ] Define a versioned operation catalog before changing prompts:
  - [ ] `insert_pattern`
  - [ ] `replace_block_with_pattern`
- [ ] Define required fields, allowed target types, validation errors, and rollback payloads for each operation.
- [ ] Reject unknown operation types, unknown pattern names, stale targets, invalid insertion positions, and cross-surface targets.
- [ ] Add golden parser fixtures for valid, invalid, and partially valid operation payloads.
- [ ] Build allowed pattern context from deterministic editor/runtime state, not from the LLM.
- [ ] Include only patterns that are visible, readable, insertable in the current context, and permitted for the selected target.
- [ ] Include allowed action metadata per pattern: `insert_before`, `insert_after`, `replace`.
- [ ] Require the model to choose only from this list.
- [ ] Revalidate the chosen pattern against the live editor state immediately before apply.
- [ ] Extend block response schema only after prompt, parser, REST, JS store, and tests are updated together.
- [ ] Support only two first-pass operations:
  - [ ] Insert allowed pattern before/after selected block.
  - [ ] Replace selected block with allowed pattern.
- [ ] Extract shared structural operation helpers instead of reusing template-part-specific helpers directly.
- [ ] Add block-keyed review state, since existing executable-surface state is not keyed by `clientId`.
- [ ] Require review before apply.
- [ ] Revalidate live editor tree, selected block identity, locks, and allowed pattern availability immediately before mutation.
- [ ] Capture pre-apply and post-apply structural signatures.
- [ ] Allow undo only if the current live editor state matches the expected post-apply signature.
- [ ] If live state diverged, disable automatic undo and show manual recovery guidance.
- [ ] Ensure failed apply leaves the editor unchanged.

Allowed pattern context shape:

```ts
type AllowedPatternForRecommendation = {
  name: string;
  title: string;
  source: 'core' | 'theme' | 'plugin' | 'user';
  categories?: string[];
  blockTypes?: string[];
  allowedActions: Array<'insert_before' | 'insert_after' | 'replace'>;
};
```

### Phase 3A: Runtime Apply Safety

- [ ] Capture recommendation-time target signature.
- [ ] Capture pre-apply live target signature.
- [ ] Capture post-apply signature for undo validation.
- [ ] Require all structural applies to be transactional.
- [ ] Disable undo if the live state no longer matches the post-apply signature.
- [ ] Fall back to advisory when feature flag, provider, pattern scope, target, lock, or schema validation fails.

### Phase 4: Improve Style Recommendations

- [ ] Add deterministic contrast/readability validation before executable color operations are returned.
- [ ] Prefer paired foreground/background style operations when a single color change would risk poor contrast.
- [ ] Classify style suggestions as advisory if any operation is individually valid but the combined foreground/background result fails deterministic readability checks.
- [ ] Preserve paired operations as one review-safe transaction; do not split them into independent applies if splitting would create an invalid intermediate state.
- [ ] Keep raw CSS, `customCSS`, unsupported paths, and non-theme-backed values advisory-only.
- [ ] Consider new executable paths only when stable in core: button pseudo-states, block element selectors, native variation previews.
- [ ] Add tests for invalid preset values, unsupported paths, low-contrast color pairs, and valid paired operations.

### Phase 5: Documentation

- [ ] Update `docs/FEATURE_SURFACE_MATRIX.md`.
- [ ] Update the touched feature docs under `docs/features/`.
- [ ] Update `docs/reference/abilities-and-routes.md` for any REST/Abilities contract change.
- [ ] Update `docs/reference/recommendation-ui-consistency.md` for UI taxonomy changes.
- [ ] Add an upstream-alignment note if work touches abilities, observability, provider routing, or pattern/block infrastructure.

### Phase 6: Verification

- [ ] Run targeted PHPUnit for touched ability/prompt classes.
- [ ] Run targeted JS unit tests for touched store/UI helpers.
- [ ] Run `npm run check:docs` if docs or contracts changed.
- [ ] Run `node scripts/verify.js --skip-e2e`.
- [ ] Run `npm run test:e2e:playground` for block/pattern changes.
- [ ] Run `npm run test:e2e:wp70` for style/Site Editor changes.
- [ ] Record any unavailable or known-red browser harness explicitly.

### Phase 6A: Race And Negative Tests

- [ ] Recommendation generated, selected block deleted before apply.
- [ ] Recommendation generated, selected block moved before apply.
- [ ] Target becomes locked before apply.
- [ ] Target enters content-only mode before apply.
- [ ] Pattern disappears before apply.
- [ ] Pattern remains visible but is no longer valid for the insertion context.
- [ ] User edits inserted result before undo.
- [ ] Multi-operation apply fails halfway; editor remains unchanged.
